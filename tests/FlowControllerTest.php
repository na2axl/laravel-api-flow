<?php

declare(strict_types=1);

namespace Na2axl\LaravelApiFlow\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Na2axl\LaravelApiFlow\Tests\Fixtures\SignupFlowController;

class FlowControllerTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        Route::flow('signup', SignupFlowController::class);
    }

    private function startFlow(): string
    {
        $response = $this->getJson('/signup/initiate');
        $response->assertOk()
            ->assertJsonPath('next_steps', ['email'])
            ->assertJsonPath('back_steps', [])
            ->assertJsonStructure(['flow_key']);

        return $response->json('flow_key');
    }

    public function test_the_macro_registers_one_route_per_step_method(): void
    {
        $this->assertTrue(Route::has('flow.signup.initiate'));
        $this->assertTrue(Route::has('flow.signup.email'));
        $this->assertTrue(Route::has('flow.signup.plan'));
        $this->assertTrue(Route::has('flow.signup.confirm'));

        $initiate = Route::getRoutes()->getByName('flow.signup.initiate');
        $this->assertContains('GET', $initiate->methods());

        $email = Route::getRoutes()->getByName('flow.signup.email');
        $this->assertContains('POST', $email->methods());
    }

    public function test_a_flow_progresses_through_its_steps(): void
    {
        $flowKey = $this->startFlow();

        $this->postJson('/signup/email', ['flow_key' => $flowKey, 'email' => 'axel@example.com'])
            ->assertOk()
            ->assertJsonPath('next_steps', ['plan']);

        $this->postJson('/signup/plan', ['flow_key' => $flowKey, 'plan' => 'pro'])
            ->assertOk()
            ->assertJsonPath('next_steps', ['confirm'])
            ->assertJsonPath('data.state.email', 'axel@example.com')
            ->assertJsonPath('data.state.plan', 'pro');

        $this->postJson('/signup/confirm', ['flow_key' => $flowKey])
            ->assertOk()
            ->assertJsonPath('next_steps', []);
    }

    public function test_the_flow_is_forgotten_once_completed(): void
    {
        $flowKey = $this->startFlow();

        $this->postJson('/signup/email', ['flow_key' => $flowKey, 'email' => 'axel@example.com']);
        $this->postJson('/signup/plan', ['flow_key' => $flowKey, 'plan' => 'pro']);
        $this->postJson('/signup/confirm', ['flow_key' => $flowKey]);

        $this->assertFalse(Cache::has("signup:{$flowKey}"));
    }

    public function test_unknown_flow_keys_get_a_410(): void
    {
        // A well-formed ULID that no flow owns: authorize() throws 410.
        $this->postJson('/signup/email', [
            'flow_key' => '01J8Q0ZK0Z8ZK0Z8ZK0Z8ZK0Z8',
            'email' => 'axel@example.com',
        ])->assertStatus(410)
            ->assertJsonPath('code', 'FLOW_EXPIRED');
    }

    public function test_malformed_flow_keys_get_a_422(): void
    {
        $this->postJson('/signup/email', ['flow_key' => 'not-a-ulid', 'email' => 'axel@example.com'])
            ->assertStatus(422);
    }

    public function test_missing_step_fields_get_a_422(): void
    {
        $flowKey = $this->startFlow();

        $this->postJson('/signup/email', ['flow_key' => $flowKey])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_skipping_steps_gets_a_409(): void
    {
        $flowKey = $this->startFlow();

        $this->postJson('/signup/plan', ['flow_key' => $flowKey, 'plan' => 'pro'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'FLOW_UNEXPECTED_STEP');
    }

    public function test_reanswering_an_advertised_back_step_rewinds_the_flow(): void
    {
        $flowKey = $this->startFlow();

        $this->postJson('/signup/email', ['flow_key' => $flowKey, 'email' => 'axel@example.com']);

        // From "email", `email` itself is not a legal transition.
        $this->postJson('/signup/email', ['flow_key' => $flowKey, 'email' => 'again@example.com'])
            ->assertStatus(409);

        // Move to plan, then go back to email — the advertised back edge.
        $this->postJson('/signup/plan', ['flow_key' => $flowKey, 'plan' => 'pro'])
            ->assertJsonPath('back_steps', ['email']);

        $this->postJson('/signup/email', ['flow_key' => $flowKey, 'email' => 'changed@example.com'])
            ->assertOk()
            ->assertJsonPath('next_steps', ['plan']);

        $state = Cache::get("signup:{$flowKey}");
        $this->assertSame('changed@example.com', $state['email']);
        $this->assertNull($state['plan']); // downstream state discarded
    }
}
