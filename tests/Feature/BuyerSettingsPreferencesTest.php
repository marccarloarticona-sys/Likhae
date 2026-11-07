<?php

namespace Tests\Feature;

use App\Http\Controllers\Buyer\BuyerController;
use App\Http\Middleware\SetUserLocale;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Tests\TestCase;

class BuyerSettingsPreferencesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.prefix' => '',
            'database.connections.sqlite.foreign_key_constraints' => true,
        ]);
        DB::purge('sqlite');

        Schema::connection('sqlite')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('account_type')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('status')->default(User::STATUS_ACTIVE);
            $table->json('notification_preferences')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function test_unsaved_buyer_preferences_keep_the_existing_defaults(): void
    {
        $buyer = new User(['account_type' => User::TYPE_BUYER]);
        $request = Request::create('/buyer/settings', 'GET');
        $request->setUserResolver(fn () => $buyer);

        $view = app(BuyerController::class)->settings($request);
        $preferences = $view->getData()['preferences'];

        $this->assertTrue($preferences['ai_assistant_enabled']);
        $this->assertFalse($preferences['ai_response_sound']);
    }

    public function test_buyer_can_save_each_ai_preference_without_overwriting_other_account_preferences(): void
    {
        $buyer = $this->buyer('buyer-one@example.com', [
            'ai_assistant_enabled' => true,
            'ai_response_sound' => false,
            'chat_updates' => true,
        ]);
        $otherBuyer = $this->buyer('buyer-two@example.com', [
            'ai_assistant_enabled' => true,
            'ai_response_sound' => false,
        ]);

        $this->actingAs($buyer)
            ->patchJson(route('buyer.settings.ai.update'), [
                'key' => 'ai_assistant_enabled',
                'enabled' => false,
            ])
            ->assertOk()
            ->assertExactJson(['key' => 'ai_assistant_enabled', 'enabled' => false]);

        $this->actingAs($buyer)
            ->patchJson(route('buyer.settings.ai.update'), [
                'key' => 'ai_response_sound',
                'enabled' => true,
            ])
            ->assertOk()
            ->assertExactJson(['key' => 'ai_response_sound', 'enabled' => true]);

        $this->actingAs($buyer)
            ->patchJson(route('buyer.settings.ai.update'), [
                'key' => 'ai_assistant_enabled',
                'enabled' => true,
            ])
            ->assertOk()
            ->assertExactJson(['key' => 'ai_assistant_enabled', 'enabled' => true]);

        $this->actingAs($buyer)
            ->patchJson(route('buyer.settings.ai.update'), [
                'key' => 'ai_response_sound',
                'enabled' => false,
            ])
            ->assertOk()
            ->assertExactJson(['key' => 'ai_response_sound', 'enabled' => false]);

        $this->actingAs($buyer)
            ->put(route('buyer.settings.update'), [
                'theme' => 'dark',
                'language' => 'ceb',
                'chat_updates' => '1',
            ])
            ->assertRedirect();

        $buyerPreferences = $buyer->fresh()->notification_preferences;
        $this->assertTrue($buyerPreferences['ai_assistant_enabled']);
        $this->assertFalse($buyerPreferences['ai_response_sound']);
        $this->assertTrue($buyerPreferences['chat_updates']);
        $this->assertSame('ceb', $buyerPreferences['language']);
        $this->assertSame([
            'ai_assistant_enabled' => true,
            'ai_response_sound' => false,
        ], $otherBuyer->fresh()->notification_preferences);

        $response = $this->actingAs($buyer->fresh())
            ->get(route('buyer.settings'))
            ->assertOk();
        $this->assertMatchesRegularExpression('/<html\\b[^>]*\\blang="ceb"/si', $response->getContent());
    }

    public function test_invalid_settings_and_user_id_payloads_are_rejected_without_changes(): void
    {
        $buyer = $this->buyer('buyer@example.com', [
            'ai_assistant_enabled' => true,
            'ai_response_sound' => false,
            'language' => 'fil',
        ]);

        $this->actingAs($buyer)
            ->patchJson(route('buyer.settings.ai.update'), [
                'key' => 'unknown_preference',
                'enabled' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('key');

        $this->actingAs($buyer)
            ->patchJson(route('buyer.settings.ai.update'), [
                'key' => 'ai_assistant_enabled',
                'enabled' => 'sometimes',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('enabled');

        $this->actingAs($buyer)
            ->patchJson(route('buyer.settings.ai.update'), [
                'key' => 'ai_assistant_enabled',
                'enabled' => false,
                'user_id' => $buyer->id,
            ])
            ->assertUnprocessable();

        $this->actingAs($buyer)
            ->put(route('buyer.settings.update'), [
                'theme' => 'dark',
                'language' => 'fr',
            ])
            ->assertSessionHasErrors('language');
        $this->assertStringContainsString('Dapat pumili ng wastong wika.', session('errors')->first('language'));

        $this->assertSame([
            'ai_assistant_enabled' => true,
            'ai_response_sound' => false,
            'language' => 'fil',
        ], $buyer->fresh()->notification_preferences);
    }

    public function test_buyer_settings_document_uses_the_saved_language(): void
    {
        $buyer = $this->buyer('buyer-locale@example.com', ['language' => 'fil']);
        App::setLocale('fil');

        $response = $this->actingAs($buyer)
            ->get(route('buyer.settings'))
            ->assertOk()
            ->assertSee('name="language"', false)
            ->assertSee('value="en"', false)
            ->assertSee('value="fil"', false)
            ->assertSee('value="ceb"', false)
            ->assertSee('Mga Setting', false);
        $this->assertMatchesRegularExpression('/<html\\b[^>]*\\blang="fil"/si', $response->getContent());
    }

    public function test_buyer_can_save_each_supported_language_without_losing_other_preferences(): void
    {
        $buyer = $this->buyer('buyer-all-locales@example.com', ['existing_preference' => 'keep-me']);

        foreach (array_keys(config('app.supported_locales')) as $locale) {
            $this->actingAs($buyer)
                ->put(route('buyer.settings.update'), [
                    'theme' => 'system',
                    'language' => $locale,
                ])
                ->assertRedirect();

            $preferences = $buyer->fresh()->notification_preferences;
            $this->assertSame($locale, $preferences['language']);
            $this->assertSame('keep-me', $preferences['existing_preference']);
        }
    }

    public function test_locale_middleware_accepts_supported_values_and_defaults_to_english(): void
    {
        $middleware = new SetUserLocale;
        $request = Request::create('/locale-check');
        $user = new User(['notification_preferences' => ['language' => 'ceb']]);
        $request->setUserResolver(fn () => $user);

        $response = $middleware->handle($request, fn () => response('ok'));

        $this->assertSame('ceb', App::getLocale());
        $this->assertSame('ceb', $response->headers->get('Content-Language'));

        $user->notification_preferences = ['language' => 'fr'];
        $response = $middleware->handle($request, fn () => response('ok'));
        $this->assertSame('en', App::getLocale());
        $this->assertSame('en', $response->headers->get('Content-Language'));

        $request->setUserResolver(fn () => null);
        $response = $middleware->handle($request, fn () => response('ok'));
        $this->assertSame('en', App::getLocale());
        $this->assertSame('en', $response->headers->get('Content-Language'));
    }

    public function test_ai_widget_markup_keeps_visibility_and_sound_preferences_independent(): void
    {
        $buyer = $this->buyer('buyer@example.com', [
            'ai_assistant_enabled' => false,
            'ai_response_sound' => true,
        ]);
        $this->actingAs($buyer);

        $hiddenWidget = view('components.buyer.ai-assistant', [
            'page' => 'home',
            'pageTitle' => 'Home',
            'visible' => false,
        ])->render();
        $visibleWidget = view('components.buyer.ai-assistant', [
            'page' => 'settings',
            'pageTitle' => 'Settings',
            'visible' => true,
        ])->render();

        $this->assertStringContainsString('data-ai-assistant-enabled="0"', $hiddenWidget);
        $this->assertStringContainsString('data-ai-response-sound="1"', $hiddenWidget);
        $this->assertMatchesRegularExpression('/<section[^>]*\shidden(?:\s|>)/', $hiddenWidget);
        $this->assertStringContainsString('data-ai-assistant-enabled="1"', $visibleWidget);
        $this->assertStringContainsString('data-ai-response-sound="1"', $visibleWidget);
        $this->assertStringNotContainsString('data-ai-assistant-enabled="1" hidden', $visibleWidget);
    }

    public function test_update_endpoint_remains_buyer_authenticated_and_inside_the_web_csrf_group(): void
    {
        $middleware = Route::getRoutes()->getByName('buyer.settings.ai.update')->gatherMiddleware();

        $this->assertContains('web', $middleware);
        $this->assertContains('auth', $middleware);
        $this->assertContains('verified', $middleware);
        $this->assertContains('App\\Http\\Middleware\\EnsureWorkspaceRole:buyer', $middleware);

        $this->patchJson(route('buyer.settings.ai.update'), [
            'key' => 'ai_assistant_enabled',
            'enabled' => false,
        ])->assertUnauthorized();
    }

    private function buyer(string $email, array $preferences, string $type = User::TYPE_BUYER): User
    {
        return User::query()->create([
            'account_type' => $type,
            'first_name' => 'Test',
            'last_name' => 'Buyer',
            'email' => $email,
            'password' => 'test-password',
            'email_verified_at' => Carbon::now(),
            'status' => User::STATUS_ACTIVE,
            'notification_preferences' => $preferences,
        ]);
    }
}
