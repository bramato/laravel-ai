<?php

namespace Bramato\LaravelAi\Tests\Unit;

use Bramato\LaravelAi\LaravelAiServiceProvider;
use Bramato\LaravelAi\Models\LlmModel;
use Illuminate\Database\Eloquent\Collection;
use Orchestra\Testbench\TestCase;

class LlmModelTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [
            LaravelAiServiceProvider::class,
        ];
    }

    // Basic tests to ensure the Sushi model LlmModel works as expected.

    /** @test */
    public function it_can_retrieve_all_models()
    {
        $models = LlmModel::all();
        $this->assertInstanceOf(Collection::class, $models);
        $this->assertGreaterThan(0, $models->count());
    }

    /** @test */
    public function it_can_retrieve_the_first_model()
    {
        $model = LlmModel::first();
        $this->assertInstanceOf(LlmModel::class, $model);
        $this->assertIsString($model->provider);
        $this->assertIsString($model->model_id);
    }

    /** @test */
    public function it_casts_attributes_correctly()
    {
        $model = LlmModel::where('provider', 'openai')->where('model_id', 'gpt-4o')->first();
        $this->assertNotNull($model);
        $this->assertIsInt($model->context_window);
        $this->assertSame(128000, $model->context_window);
        $this->assertIsBool($model->json_mode);
        $this->assertTrue($model->json_mode);
        $this->assertIsBool($model->supports_vision);
        $this->assertTrue($model->supports_vision);
        $this->assertIsInt($model->max_output_tokens);
        $this->assertSame(16384, $model->max_output_tokens);

        $deepseekModel = LlmModel::where('provider', 'deepseek')->first();
        $this->assertNotNull($deepseekModel);
        $this->assertIsBool($deepseekModel->json_mode);
        $this->assertFalse($deepseekModel->json_mode);
        $this->assertIsBool($deepseekModel->supports_vision);
        $this->assertFalse($deepseekModel->supports_vision);
    }

    /** @test */
    public function it_can_query_models_by_provider()
    {
        $openaiModels = LlmModel::where('provider', 'openai')->get();
        $this->assertInstanceOf(Collection::class, $openaiModels);
        $this->assertGreaterThan(0, $openaiModels->count());
        $this->assertTrue($openaiModels->every(fn($model) => $model->provider === 'openai'));

        $googleModels = LlmModel::where('provider', 'google')->get();
        $this->assertInstanceOf(Collection::class, $googleModels);
        $this->assertGreaterThan(0, $googleModels->count());
        $this->assertTrue($googleModels->every(fn($model) => $model->provider === 'google'));
    }

    /** @test */
    public function it_can_query_models_by_model_id()
    {
        $model = LlmModel::where('model_id', 'claude-3.5-sonnet-20241022')->first();
        $this->assertInstanceOf(LlmModel::class, $model);
        $this->assertSame('anthropic', $model->provider);
    }

    /** @test */
    public function it_can_query_models_supporting_vision()
    {
        $visionModels = LlmModel::where('supports_vision', true)->get();
        $this->assertInstanceOf(Collection::class, $visionModels);
        $this->assertGreaterThan(0, $visionModels->count());
        $this->assertTrue($visionModels->every(fn($model) => $model->supports_vision === true));
    }

    /** @test */
    public function it_can_query_models_supporting_json_mode()
    {
        $jsonModels = LlmModel::where('json_mode', true)->get();
        $this->assertInstanceOf(Collection::class, $jsonModels);
        $this->assertGreaterThan(0, $jsonModels->count());
        $this->assertTrue($jsonModels->every(fn($model) => $model->json_mode === true));

        $noJsonModels = LlmModel::where('json_mode', false)->get();
        $this->assertInstanceOf(Collection::class, $noJsonModels);
        $this->assertGreaterThan(0, $noJsonModels->count());
        $this->assertTrue($noJsonModels->every(fn($model) => $model->json_mode === false));
    }
}
