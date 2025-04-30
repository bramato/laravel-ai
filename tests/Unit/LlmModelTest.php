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
        $model = LlmModel::where('model_id', 'gpt-4o')->first(); // A model with known values
        $this->assertNotNull($model);
        $this->assertIsInt($model->context_window);
        $this->assertEquals(128000, $model->context_window);
        $this->assertIsBool($model->json_mode);
        $this->assertTrue($model->json_mode);
        $this->assertIsBool($model->supports_vision);
        $this->assertTrue($model->supports_vision);
        $this->assertIsInt($model->max_output_tokens);
        $this->assertEquals(16384, $model->max_output_tokens);
        $this->assertIsBool($model->flagship); // Check flagship cast
        $this->assertFalse($model->flagship); // gpt-4o is not flagship in this test data
    }

    /** @test */
    public function it_can_query_models_by_provider()
    {
        $openaiModels = LlmModel::where('provider', 'openai')->get();
        $this->assertInstanceOf(Collection::class, $openaiModels);
        $this->assertGreaterThan(0, $openaiModels->count());
        foreach ($openaiModels as $model) {
            $this->assertEquals('openai', $model->provider);
        }

        $googleModels = LlmModel::where('provider', 'google')->get();
        $this->assertInstanceOf(Collection::class, $googleModels);
        $this->assertGreaterThan(0, $googleModels->count());
        foreach ($googleModels as $model) {
            $this->assertEquals('google', $model->provider);
        }
    }

    /** @test */
    public function it_can_query_models_by_model_id()
    {
        $modelId = 'claude-3-haiku-20240307';
        $model = LlmModel::where('model_id', $modelId)->first();
        $this->assertInstanceOf(LlmModel::class, $model);
        $this->assertEquals($modelId, $model->model_id);
    }

    /** @test */
    public function it_can_query_models_supporting_vision()
    {
        $visionModels = LlmModel::where('supports_vision', true)->get();
        $this->assertInstanceOf(Collection::class, $visionModels);
        $this->assertGreaterThan(0, $visionModels->count());
        foreach ($visionModels as $model) {
            $this->assertTrue($model->supports_vision);
        }

        $noVisionModels = LlmModel::where('supports_vision', false)->get();
        $this->assertInstanceOf(Collection::class, $noVisionModels);
        $this->assertGreaterThan(0, $noVisionModels->count());
        foreach ($noVisionModels as $model) {
            $this->assertFalse($model->supports_vision);
        }
    }

    /** @test */
    public function it_can_query_models_supporting_json_mode()
    {
        $jsonModels = LlmModel::where('json_mode', true)->get();
        $this->assertInstanceOf(Collection::class, $jsonModels);
        $this->assertGreaterThan(0, $jsonModels->count());
        foreach ($jsonModels as $model) {
            $this->assertTrue($model->json_mode);
        }

        $noJsonModels = LlmModel::where('json_mode', false)->get();
        $this->assertInstanceOf(Collection::class, $noJsonModels);
        $this->assertGreaterThan(0, $noJsonModels->count());
        foreach ($noJsonModels as $model) {
            $this->assertFalse($model->json_mode);
        }
    }

    /** @test */
    public function it_can_query_flagship_models()
    {
        $flagshipModels = LlmModel::where('flagship', true)->get();
        $this->assertInstanceOf(Collection::class, $flagshipModels);
        $this->assertCount(4, $flagshipModels); // Expecting one per provider
        foreach ($flagshipModels as $model) {
            $this->assertTrue($model->flagship);
        }

        $nonFlagshipModels = LlmModel::where('flagship', false)->get();
        $this->assertInstanceOf(Collection::class, $nonFlagshipModels);
        $this->assertGreaterThan(4, $nonFlagshipModels->count());
        foreach ($nonFlagshipModels as $model) {
            $this->assertFalse($model->flagship);
        }
    }

    /** @test */
    public function it_can_retrieve_specific_flagship_models_via_static_methods()
    {
        $openai = LlmModel::openAiFlagship();
        $this->assertInstanceOf(LlmModel::class, $openai);
        $this->assertEquals('openai', $openai->provider);
        $this->assertTrue($openai->flagship);
        $this->assertEquals('gpt-4.1', $openai->model_id); // Verify correct model ID

        $google = LlmModel::geminiFlagship();
        $this->assertInstanceOf(LlmModel::class, $google);
        $this->assertEquals('google', $google->provider);
        $this->assertTrue($google->flagship);
        $this->assertEquals('gemini-2.5-pro-preview', $google->model_id);

        $claude = LlmModel::claudeFlagship();
        $this->assertInstanceOf(LlmModel::class, $claude);
        $this->assertEquals('anthropic', $claude->provider);
        $this->assertTrue($claude->flagship);
        $this->assertEquals('claude-3.7-sonnet-20250219', $claude->model_id);

        $deepseek = LlmModel::deepSeekFlagship();
        $this->assertInstanceOf(LlmModel::class, $deepseek);
        $this->assertEquals('deepseek', $deepseek->provider);
        $this->assertTrue($deepseek->flagship);
        $this->assertEquals('deepseek-chat', $deepseek->model_id);
    }
}
