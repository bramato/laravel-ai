<?php

namespace Bramato\LaravelAi\Tests\Feature\Services;

use Bramato\LaravelAi\Clients\OpenAiClient;
use Bramato\LaravelAi\Contracts\ImageDescriptionServiceInterface;
use Bramato\LaravelAi\DTOs\ChatRequest;
use Bramato\LaravelAi\DTOs\ChatResponse;
use Bramato\LaravelAi\DTOs\ImageDescriptionResponseDto;
use Bramato\LaravelAi\Tests\TestCase;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http; // Use Http facade for mocking downloads
use Mockery\MockInterface;

class ImageDescriptionServiceTest extends TestCase
{
    // Helper to mock successful OpenAI response
    private function mockSuccessResponse(string $description): ChatResponse
    {
        return new ChatResponse([
            'id' => 'chat-img-test-123',
            'model' => 'gpt-4o-test',
            'content' => $description,
            'finishReason' => 'stop',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30],
            'isJson' => false,
            'decodedJsonContent' => null,
            'rawResponse' => ['mock' => true],
        ]);
    }

    /** @test */
    public function it_can_describe_an_image_from_uploaded_file(): void
    {
        $description = 'A mock description of the uploaded image.';
        $fakeFile = UploadedFile::fake()->image('test_image.jpg', 100, 100)->size(100); // 100KB

        // Mock the specific OpenAiClient dependency
        $mockOpenAiClient = $this->mock(OpenAiClient::class, function (MockInterface $mock) use ($description) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(function (ChatRequest $request) {
                    $this->assertStringContainsString('Describe this image', $request->prompt);
                    $this->assertIsArray($request->images);
                    $this->assertCount(1, $request->images);
                    $this->assertStringStartsWith('data:image/jpeg;base64,', $request->images[0]);

                    return true;
                })
                ->andReturn($this->mockSuccessResponse($description));
        });

        // No need to mock HttpClientFactory as local file processing doesn't use it

        // Resolve the service using the bound interface
        $service = $this->app->make(ImageDescriptionServiceInterface::class);

        $result = $service->describe($fakeFile);

        $this->assertInstanceOf(ImageDescriptionResponseDto::class, $result);
        $this->assertEquals($description, $result->description);
    }

    /** @test */
    public function it_can_describe_an_image_from_url(): void
    {
        $description = 'A description from a URL image.';
        $imageUrl = 'http://example.com/image.png';
        $fakeImageContent = 'fake-png-content';

        // Mock the HTTP client for downloading the image
        Http::fake([
            $imageUrl => Http::response($fakeImageContent, 200, ['Content-Type' => 'image/png']),
        ]);

        $mockOpenAiClient = $this->mock(OpenAiClient::class, function (MockInterface $mock) use ($description, $fakeImageContent) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(function (ChatRequest $request) use ($fakeImageContent) {
                    $this->assertStringContainsString('Describe this image', $request->prompt);
                    $this->assertCount(1, $request->images ?? []);
                    $expectedBase64 = 'data:image/png;base64,' . base64_encode($fakeImageContent);
                    $this->assertEquals($expectedBase64, $request->images[0]);

                    return true;
                })
                ->andReturn($this->mockSuccessResponse($description));
        });

        $service = $this->app->make(ImageDescriptionServiceInterface::class);
        $result = $service->describe($imageUrl);

        $this->assertInstanceOf(ImageDescriptionResponseDto::class, $result);
        $this->assertEquals($description, $result->description);
        Http::assertSent(fn(Request $request) => $request->url() === $imageUrl);
    }

    /** @test */
    public function it_can_describe_an_image_from_local_path(): void
    {
        $description = 'Description from local path.';
        // Use Orchestra Testbench's fixture path if available, or create a dummy file
        $fixturePath = __DIR__ . '/../Fixtures/test_image.webp'; // Assuming a fixture exists
        if (! file_exists(dirname($fixturePath))) {
            mkdir(dirname($fixturePath), 0777, true);
        }
        file_put_contents($fixturePath, 'fake-webp-content'); // Create dummy file
        $this->assertTrue(file_exists($fixturePath));

        // Mock mime type detection if needed (Laravel File facade might handle it)
        // Facades::shouldReceive('mimeType')->with($fixturePath)->andReturn('image/webp');

        $mockOpenAiClient = $this->mock(OpenAiClient::class, function (MockInterface $mock) use ($description) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(function (ChatRequest $request) {
                    $this->assertStringStartsWith('data:image/webp;base64,', $request->images[0] ?? '');

                    return true;
                })
                ->andReturn($this->mockSuccessResponse($description));
        });

        $service = $this->app->make(ImageDescriptionServiceInterface::class);
        $result = $service->describe($fixturePath);

        $this->assertInstanceOf(ImageDescriptionResponseDto::class, $result);
        $this->assertEquals($description, $result->description);

        // Clean up dummy file
        unlink($fixturePath);
        rmdir(dirname($fixturePath));
    }

    /** @test */
    public function it_uses_custom_prompt_and_options(): void
    {
        $description = 'Focused description.';
        $customPrompt = 'Focus on the animals in the image.';
        $options = ['temperature' => 0.1]; // Example option
        $fakeFile = UploadedFile::fake()->image('animal.jpg');

        $mockOpenAiClient = $this->mock(OpenAiClient::class, function (MockInterface $mock) use ($description, $customPrompt, $options) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(function (ChatRequest $request) use ($customPrompt, $options) {
                    $this->assertEquals($customPrompt, $request->prompt);
                    $this->assertEquals($options['temperature'], $request->options['temperature'] ?? null);

                    // Check if model preference is passed (if we decide to test that)
                    // $this->assertEquals('gpt-4o-test', $request->options['model'] ?? null);
                    return true;
                })
                ->andReturn($this->mockSuccessResponse($description));
        });

        $service = $this->app->make(ImageDescriptionServiceInterface::class);
        // Assume gpt-4o-test model exists and supports vision
        // $model = LlmModel::where('model_id', 'gpt-4o-test')->first();
        // $this->assertNotNull($model);

        $result = $service->describe($fakeFile, $customPrompt, null, $options);

        $this->assertEquals($description, $result->description);
    }

    /** @test */
    public function it_returns_fallback_if_openai_call_fails(): void
    {
        $fakeFile = UploadedFile::fake()->image('fail.jpg');

        $mockOpenAiClient = $this->mock(OpenAiClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->andThrow(new \Exception('API communication error'));
        });

        // Mock report helper
        if (! function_exists('Bramato\LaravelAi\Services\report')) {
            eval('namespace Bramato\LaravelAi\Services; function report($exception) { /* No-op */ }');
        }

        $service = $this->app->make(ImageDescriptionServiceInterface::class);
        $result = $service->describe($fakeFile);

        $this->assertInstanceOf(ImageDescriptionResponseDto::class, $result);
        $this->assertEquals('Image description not available.', $result->description); // Default fallback
    }

    /** @test */
    public function it_throws_exception_for_invalid_image_source(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid image source provided');

        $service = $this->app->make(ImageDescriptionServiceInterface::class);
        $service->describe('nonexistent/path/image.jpg');
    }

    /** @test */
    public function it_throws_exception_for_unsupported_mime_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported image MIME type');

        $fakeFile = UploadedFile::fake()->create('document.txt', 100, 'text/plain');

        $service = $this->app->make(ImageDescriptionServiceInterface::class);
        $service->describe($fakeFile);
    }

    /** @test */
    public function it_throws_exception_for_image_too_large(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds the maximum allowed limit');

        // Create a fake file larger than the 10MB limit set in the service
        $fakeFile = UploadedFile::fake()->image('large_image.jpg')->size(15 * 1024); // 15MB

        $service = $this->app->make(ImageDescriptionServiceInterface::class);
        $service->describe($fakeFile);
    }

    /** @test */
    public function it_returns_fallback_if_url_download_fails(): void
    {
        $imageUrl = 'http://example.com/broken.jpg';
        Http::fake([$imageUrl => Http::response(null, 500)]); // Simulate download error

        // Mock report helper
        if (! function_exists('Bramato\LaravelAi\Services\report')) {
            eval('namespace Bramato\LaravelAi\Services; function report($exception) { /* No-op */ }');
        }

        $service = $this->app->make(ImageDescriptionServiceInterface::class);
        $result = $service->describe($imageUrl);

        $this->assertInstanceOf(ImageDescriptionResponseDto::class, $result);
        $this->assertEquals('Image description not available.', $result->description);
        Http::assertSent(fn(Request $request) => $request->url() === $imageUrl);
    }
}
