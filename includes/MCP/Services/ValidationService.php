<?php
/**
 * Bricks element validation service.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

use Opis\JsonSchema\Validator;
use Opis\JsonSchema\ValidationResult;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ValidationService class.
 *
 * Validates Bricks element JSON against generated schemas using Opis JSON Schema.
 * Provides detailed error messages with JSON paths and fix suggestions.
 */
class ValidationService {

	/**
	 * Schema generator instance.
	 *
	 * @var SchemaGenerator
	 */
	private SchemaGenerator $schema_generator;

	/**
	 * Constructor.
	 *
	 * @param SchemaGenerator $schema_generator Schema generator instance.
	 */
	public function __construct( SchemaGenerator $schema_generator ) {
		$this->schema_generator = $schema_generator;
	}

	/**
	 * Validate a single Bricks element against its type schema.
	 *
	 * Checks element structure and validates settings against the element type's
	 * JSON Schema. Permissive on unknown settings keys (addons may add arbitrary settings).
	 *
	 * @param array<string, mixed> $element Element to validate.
	 * @return true|\WP_Error True if valid, WP_Error with validation details on failure.
	 */
	public function validate_element( array $element ): bool|\WP_Error {
		// Check element has required 'name' key.
		if ( ! isset( $element['name'] ) || ! is_string( $element['name'] ) ) {
			return new \WP_Error(
				'validation_failed',
				__( 'Element is missing required "name" field.', 'bricks-mcp' ),
				[
					'element_id'   => $element['id'] ?? 'unknown',
					'element_type' => 'unknown',
					'errors'       => [
						[
							'path'       => 'name',
							'message'    => __( 'Required field "name" is missing or not a string.', 'bricks-mcp' ),
							'suggestion' => __( 'Add a "name" field with the element type (e.g., "heading", "section"). Use get_element_schemas to discover valid element types.', 'bricks-mcp' ),
						],
					],
				]
			);
		}

		$element_type = $element['name'];

		// Skip schema validation for component instances (element name = component ID, has 'cid' key).
		if ( isset( $element['cid'] ) ) {
			return true;
		}

		// Get schema for element type.
		$schema_result = $this->schema_generator->get_element_schema( $element_type );

		if ( is_wp_error( $schema_result ) ) {
			// If Bricks is not active, skip schema validation (structural checks only).
			if ( 'bricks_not_active' === $schema_result->get_error_code() ) {
				return true;
			}

			// Unknown element type.
			$error_data  = $schema_result->get_error_data();
			$suggestions = $error_data['suggestions'] ?? [];

			$suggestion_text = empty( $suggestions )
				? __( 'Use get_element_schemas to discover valid element types.', 'bricks-mcp' )
				: sprintf(
					/* translators: %s: Comma-separated list of suggested element types */
					__( 'Did you mean one of: %s? Use get_element_schemas to see all available element types.', 'bricks-mcp' ),
					implode( ', ', $suggestions )
				);

			return new \WP_Error(
				'unknown_element_type',
				sprintf(
					/* translators: %s: Element type name */
					__( 'Unknown element type: "%s".', 'bricks-mcp' ),
					$element_type
				),
				[
					'element_id'   => $element['id'] ?? 'unknown',
					'element_type' => $element_type,
					'errors'       => [
						[
							'path'       => 'name',
							'message'    => sprintf(
								/* translators: %s: Element type name */
								__( '"%s" is not a registered Bricks element type.', 'bricks-mcp' ),
								$element_type
							),
							'suggestion' => $suggestion_text,
						],
					],
				]
			);
		}

		// Validate element settings against schema using Opis.
		$settings        = $element['settings'] ?? [];
		$settings_schema = $schema_result['settings_schema'] ?? null;

		if ( null !== $settings_schema && class_exists( Validator::class ) ) {
			$opis_errors = $this->validate_with_opis( $settings, $settings_schema, $element_type );

			if ( ! empty( $opis_errors ) ) {
				return new \WP_Error(
					'validation_failed',
					sprintf(
						/* translators: %s: Element type name */
						__( 'Element "%s" settings failed validation.', 'bricks-mcp' ),
						$element_type
					),
					[
						'element_id'   => $element['id'] ?? 'unknown',
						'element_type' => $element_type,
						'errors'       => $opis_errors,
					]
				);
			}
		}

		return true;
	}

	/**
	 * Validate all elements in a flat array.
	 *
	 * Validates each element and aggregates all errors. Returns WP_Error with
	 * all errors if any element fails validation.
	 *
	 * @param array<int, array<string, mixed>> $elements Flat array of elements.
	 * @return true|\WP_Error True if all valid, WP_Error with aggregated errors on failure.
	 */
	public function validate_elements( array $elements ): bool|\WP_Error {
		$all_errors = [];

		foreach ( $elements as $index => $element ) {
			$result = $this->validate_element( $element );

			if ( is_wp_error( $result ) ) {
				$error_data = $result->get_error_data();
				$errors     = $error_data['errors'] ?? [];

				// Prefix paths with element index.
				foreach ( $errors as &$error ) {
					$existing_path = $error['path'] ?? '';
					$error['path'] = "elements[{$index}].settings" . ( $existing_path ? ".{$existing_path}" : '' );
				}
				unset( $error );

				$all_errors[] = [
					'element_index' => $index,
					'element_id'    => $element['id'] ?? 'unknown',
					'element_type'  => $element['name'] ?? 'unknown',
					'error_code'    => $result->get_error_code(),
					'message'       => $result->get_error_message(),
					'errors'        => $errors,
				];
			}
		}

		if ( ! empty( $all_errors ) ) {
			$error_count = count( $all_errors );

			return new \WP_Error(
				'validation_failed',
				sprintf(
					/* translators: %d: Number of invalid elements */
					_n(
						'%d element failed validation.',
						'%d elements failed validation.',
						$error_count,
						'bricks-mcp'
					),
					$error_count
				),
				[
					'total_elements'   => count( $elements ),
					'invalid_elements' => $error_count,
					'errors'           => $all_errors,
				]
			);
		}

		return true;
	}

	/**
	 * Generate a helpful fix suggestion based on error type.
	 *
	 * @param string $path          JSON path to the error location.
	 * @param string $error_message The error message from the validator.
	 * @param string $element_type  The element type name.
	 * @return string Fix suggestion text.
	 */
	public function suggest_fix( string $path, string $error_message, string $element_type ): string {
		$field = basename( $path );

		// Missing required field.
		if ( str_contains( $error_message, 'required' ) || str_contains( $error_message, 'Required' ) ) {
			return sprintf(
				/* translators: %s: Field name */
				__( 'Add the "%s" property to element settings.', 'bricks-mcp' ),
				$field
			);
		}

		// Invalid type.
		if ( preg_match( '/expected\s+(\w+)\s+but\s+got\s+(\w+)/i', $error_message, $matches ) ) {
			return sprintf(
				/* translators: 1: Expected type, 2: Actual type */
				__( 'Expected %1$s but got %2$s. Provide a valid %1$s value.', 'bricks-mcp' ),
				$matches[1],
				$matches[2]
			);
		}

		// Invalid enum value.
		if ( str_contains( $error_message, 'enum' ) || str_contains( $error_message, 'allowed values' ) ) {
			return sprintf(
				/* translators: 1: Field name, 2: Element type */
				__( '"%1$s" is not a valid option for %2$s. Check get_element_schemas for valid settings.', 'bricks-mcp' ),
				$field,
				$element_type
			);
		}

		// Unknown property.
		if ( str_contains( $error_message, 'additional' ) || str_contains( $error_message, 'unknown' ) ) {
			return sprintf(
				/* translators: 1: Field name, 2: Element type */
				__( '"%1$s" is not a recognized setting for %2$s. Check get_element_schemas for valid settings.', 'bricks-mcp' ),
				$field,
				$element_type
			);
		}

		// Default suggestion.
		return sprintf(
			/* translators: %s: Element type */
			__( 'Check get_element_schemas for the correct format for %s settings.', 'bricks-mcp' ),
			$element_type
		);
	}

	/**
	 * Validate tool call arguments against a tool's inputSchema.
	 *
	 * Validates arbitrary argument data against a JSON Schema using Opis JSON Schema,
	 * returning true on success or a WP_Error with field-level details on failure.
	 *
	 * @param array<string, mixed> $arguments    Tool call arguments to validate.
	 * @param array<string, mixed> $input_schema The tool's registered inputSchema.
	 * @param string               $tool_name    Tool name for error messages.
	 * @return true|\WP_Error True if valid, WP_Error with validation details on failure.
	 */
	public function validate_arguments( array $arguments, array $input_schema, string $tool_name ): bool|\WP_Error {
		if ( ! class_exists( Validator::class ) ) {
			return new \WP_Error(
				'validation_unavailable',
				__( 'Schema validation library is not available. Tool execution blocked for safety.', 'bricks-mcp' ),
				[ 'status' => 500 ]
			);
		}

		try {
			$validator = new Validator();

			// Convert arguments and schema to JSON objects for Opis.
			// Force empty arrays to objects so JSON encodes as {} not [].
			$arguments_json = json_decode( (string) wp_json_encode( empty( $arguments ) ? new \stdClass() : $arguments ) );
			$schema_json    = json_decode( (string) wp_json_encode( empty( $input_schema ) ? new \stdClass() : $input_schema ) );

			if ( null === $arguments_json || null === $schema_json ) {
				return new \WP_Error(
					'validation_error',
					__( 'Schema validation failed unexpectedly. Tool execution blocked for safety.', 'bricks-mcp' ),
					[ 'status' => 500 ]
				);
			}

			$result = $validator->validate( $arguments_json, $schema_json );

			if ( $result->isValid() ) {
				return true;
			}

			$opis_errors = $result->error();

			if ( null === $opis_errors ) {
				return true;
			}

			$formatted_errors = $this->extract_opis_errors( $opis_errors, $tool_name );

			return new \WP_Error(
				'invalid_arguments',
				sprintf(
					/* translators: %s: Tool name */
					__( 'Tool "%s" received invalid arguments.', 'bricks-mcp' ),
					$tool_name
				),
				[ 'errors' => $formatted_errors ]
			);
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'validation_error',
				__( 'Schema validation failed unexpectedly. Tool execution blocked for safety.', 'bricks-mcp' ),
				[ 'status' => 500 ]
			);
		}
	}

	/**
	 * Validate settings against a JSON schema using Opis JSON Schema.
	 *
	 * @param array<string, mixed> $settings        Element settings to validate.
	 * @param array<string, mixed> $settings_schema JSON Schema for the settings.
	 * @param string               $element_type    Element type name for suggestions.
	 * @return array<int, array<string, string>> Array of errors with path, message, suggestion.
	 */
	private function validate_with_opis( array $settings, array $settings_schema, string $element_type ): array {
		try {
			$validator = new Validator();

			// Convert settings and schema to JSON objects for Opis.
			// Force empty arrays to objects so JSON encodes as {} not [].
			$settings_json = json_decode( (string) wp_json_encode( empty( $settings ) ? new \stdClass() : $settings ) );
			$schema_json   = json_decode( (string) wp_json_encode( $settings_schema ) );

			if ( null === $settings_json || null === $schema_json ) {
				return [];
			}

			$result = $validator->validate( $settings_json, $schema_json );

			if ( $result->isValid() ) {
				return [];
			}

			$errors      = [];
			$opis_errors = $result->error();

			if ( null === $opis_errors ) {
				return [];
			}

			$formatted = $this->extract_opis_errors( $opis_errors, $element_type );
			$errors    = array_merge( $errors, $formatted );

			return $errors;
		} catch ( \Throwable $e ) {
			// If Opis validation itself throws, log and skip — don't block saves.
			return [];
		}
	}

	/**
	 * Extract and format errors from an Opis ValidationError.
	 *
	 * Recursively processes nested errors to get leaf-level failures.
	 *
	 * @param \Opis\JsonSchema\Errors\ValidationError $error        The Opis validation error.
	 * @param string                                  $element_type Element type name.
	 * @param string                                  $path_prefix  Current JSON path prefix.
	 * @return array<int, array<string, string>> Formatted error entries.
	 */
	private function extract_opis_errors( \Opis\JsonSchema\Errors\ValidationError $error, string $element_type, string $path_prefix = '' ): array {
		$errors     = [];
		$sub_errors = $error->subErrors();

		if ( empty( $sub_errors ) ) {
			// Leaf error — format it.
			$path    = '' !== $path_prefix ? $path_prefix : '/';
			$message = $error->message();

			$errors[] = [
				'path'       => $path,
				'message'    => is_string( $message ) ? $message : 'Validation error',
				'suggestion' => $this->suggest_fix( $path, is_string( $message ) ? $message : '', $element_type ),
			];

			return $errors;
		}

		// Recurse into sub-errors.
		foreach ( $sub_errors as $sub_error ) {
			$sub_path   = $path_prefix;
			$error_path = $sub_error->data()->fullPath();

			if ( ! empty( $error_path ) ) {
				$sub_path = '/' . implode( '/', $error_path );
			}

			$errors = array_merge( $errors, $this->extract_opis_errors( $sub_error, $element_type, $sub_path ) );
		}

		return $errors;
	}
}
