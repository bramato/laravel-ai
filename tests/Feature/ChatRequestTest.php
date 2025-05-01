<?php

namespace Bramato\LaravelAi\Tests\Feature;

use Bramato\LaravelAi\DTOs\ChatRequest;
use Illuminate\Validation\ValidationException;

// Test basic instantiation with required prompt
it('can be instantiated with required data', function () {
    $data = ['prompt' => 'Hello, world!'];
    $dto = new ChatRequest($data);

    expect($dto)->toBeInstanceOf(ChatRequest::class)
        ->and($dto->prompt)->toBe('Hello, world!')
        ->and($dto->systemMessage)->toBeNull()
        ->and($dto->history)->toBe([])
        ->and($dto->options)->toBe([])
        ->and($dto->jsonMode)->toBeFalse();
});

// Test default values are applied
it('applies default values correctly', function () {
    $dto = new ChatRequest(['prompt' => 'Test prompt']);

    expect($dto->systemMessage)->toBeNull()
        ->and($dto->history)->toBe([])
        ->and($dto->options)->toBe([])
        ->and($dto->jsonMode)->toBeFalse();
});

// Test instantiation with all parameters
it('can be instantiated with all parameters', function () {
    $data = [
        'prompt' => 'Main query',
        'systemMessage' => 'You are an assistant.',
        'history' => [['role' => 'user', 'content' => 'Previous turn']],
        'options' => ['temperature' => 0.8],
        'jsonMode' => true,
    ];
    $dto = new ChatRequest($data);

    expect($dto->prompt)->toBe('Main query')
        ->and($dto->systemMessage)->toBe('You are an assistant.')
        ->and($dto->history)->toBe([['role' => 'user', 'content' => 'Previous turn']])
        ->and($dto->options)->toBe(['temperature' => 0.8])
        ->and($dto->jsonMode)->toBeTrue();
});

// Test validation fails if prompt is missing
it('throws validation exception if prompt is missing', function () {
    new ChatRequest([]);
})->throws(ValidationException::class, 'The prompt field is required.');

// Test validation fails for invalid history structure (missing role)
it('throws validation exception for invalid history structure missing role', function () {
    new ChatRequest([
        'prompt' => 'Test',
        'history' => [['content' => 'Something said']],
    ]);
})->throws(ValidationException::class, 'The history.0.role field is required');

// Test validation fails for invalid history structure (invalid role)
it('throws validation exception for invalid history role', function () {
    new ChatRequest([
        'prompt' => 'Test',
        'history' => [['role' => 'invalid-role', 'content' => 'Something said']],
    ]);
})->throws(ValidationException::class, 'The selected history.0.role is invalid.');

// Test validation fails for invalid jsonMode type
it('throws validation exception for invalid jsonMode type', function () {
    new ChatRequest(['prompt' => 'Test', 'jsonMode' => 'not-a-boolean']);
})->throws(ValidationException::class, 'The json mode field must be true or false.');

// Test boolean casting for jsonMode
it('casts jsonMode correctly from various inputs', function ($inputValue, $expectedBool) {
    $dto = new ChatRequest(['prompt' => 'Test', 'jsonMode' => $inputValue]);
    expect($dto->jsonMode)->toBe($expectedBool);
})->with([
    [true, true],
    [false, false],
    [1, true],
    [0, false],
    ['1', true],
    ['0', false],
]);
