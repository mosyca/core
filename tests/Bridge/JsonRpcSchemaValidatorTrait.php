<?php

declare(strict_types=1);

namespace Mosyca\Core\Tests\Bridge;

use JsonSchema\Validator;

/**
 * Reusable JSON-RPC 2.0 response schema validator for PHPUnit test classes.
 *
 * Validates that a decoded response array conforms to the JSON-RPC 2.0 spec:
 *   - jsonrpc === "2.0"
 *   - id is present (string, number, or null)
 *   - exactly one of "result" or "error" is present
 *   - error.code is integer, error.message is string
 *
 * Usage: add `use JsonRpcSchemaValidatorTrait;` to any TestCase subclass, then call
 * `$this->assertValidJsonRpcResponse($decodedArray)`.
 */
trait JsonRpcSchemaValidatorTrait
{
    /**
     * Assert that a decoded array conforms to the JSON-RPC 2.0 response schema.
     *
     * @param array<string, mixed> $data
     */
    private function assertValidJsonRpcResponse(array $data): void
    {
        $schema = (object) [
            'type' => 'object',
            'required' => ['jsonrpc', 'id'],
            'properties' => (object) [
                'jsonrpc' => (object) ['type' => 'string', 'enum' => ['2.0']],
                'id' => (object) ['type' => ['string', 'number', 'null']],
                'result' => (object) ['type' => ['object', 'array', 'string', 'number', 'boolean', 'null']],
                'error' => (object) [
                    'type' => 'object',
                    'required' => ['code', 'message'],
                    'properties' => (object) [
                        'code' => (object) ['type' => 'integer'],
                        'message' => (object) ['type' => 'string'],
                    ],
                ],
            ],
            'oneOf' => [
                (object) ['required' => ['result']],
                (object) ['required' => ['error']],
            ],
        ];

        $validator = new Validator();

        /** @var object $dataObject */
        $dataObject = json_decode((string) json_encode($data));
        $validator->validate($dataObject, $schema);

        self::assertTrue(
            $validator->isValid(),
            'JSON-RPC 2.0 schema validation failed: '.(string) json_encode($validator->getErrors()),
        );
    }
}
