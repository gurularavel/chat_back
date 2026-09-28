<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Widget;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Prism\Prism\Facades\Prism;
use Tests\TestCase;

class WidgetTextsTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Widget $widget;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = $this->createWorkspace(name: 'Acme');
        $this->widget = Widget::withoutGlobalScopes()->where('workspace_id', $this->workspace->id)->first();
    }

    public function test_overridden_texts_reach_the_widget_in_the_visitors_language(): void
    {
        // New widgets are titled after the workspace.
        $this->getJson("/api/widget/{$this->widget->public_key}/config?lang=az")->assertJsonPath('texts.title', 'Acme');

        $this->actingInWorkspace($this->workspace->owner, $this->workspace)
            ->patchJson("/api/widgets/{$this->widget->id}", ['texts' => [
                'az' => ['greeting' => 'Xoş gəldiniz!', 'placeholder' => '  '],
                'ru' => ['greeting' => 'Добро пожаловать!'],
            ]])
            ->assertOk()
            ->assertJsonPath('data.texts.az.greeting', 'Xoş gəldiniz!')
            ->assertJsonMissingPath('data.texts.az.placeholder'); // blank = built-in text

        $this->getJson("/api/widget/{$this->widget->public_key}/config?lang=az")
            ->assertOk()
            ->assertJsonPath('texts.greeting', 'Xoş gəldiniz!')
            ->assertJsonPath('texts.placeholder', 'Mesajınızı yazın…');

        $this->getJson("/api/widget/{$this->widget->public_key}/config?lang=ru-RU")->assertJsonPath('texts.greeting', 'Добро пожаловать!');
        $this->getJson("/api/widget/{$this->widget->public_key}/config?lang=de")->assertJsonPath('texts.greeting', 'Hi! 👋 How can I help you?');
    }

    public function test_unknown_languages_and_keys_are_rejected(): void
    {
        $this->actingInWorkspace($this->workspace->owner, $this->workspace)
            ->patchJson("/api/widgets/{$this->widget->id}", ['texts' => ['de' => ['greeting' => 'Hallo']]])
            ->assertUnprocessable();

        $this->actingInWorkspace($this->workspace->owner, $this->workspace)
            ->patchJson("/api/widgets/{$this->widget->id}", ['texts' => ['az' => ['<script>' => 'x']]])
            ->assertUnprocessable();
    }

    public function test_system_messages_use_the_widgets_wording(): void
    {
        $this->widget->update(['texts' => ['az' => ['handoff_offline' => 'Hamı məşğuldur, nömrənizi yazın.']]]);

        Prism::fake([]);
        $token = $this->postJson("/api/widget/{$this->widget->public_key}/session", ['locale' => 'az'])->json('visitor_token');
        $this->withHeader('X-Visitor-Token', $token)
            ->postJson("/api/widget/{$this->widget->public_key}/messages", ['body' => 'Operatorla danışmaq istəyirəm'])
            ->assertCreated();

        $this->assertSame('Hamı məşğuldur, nömrənizi yazın.', Message::withoutGlobalScopes()->where('sender_type', 'system')->value('body'));
    }

    public function test_placeholders_in_system_texts_are_filled(): void
    {
        $this->widget->update(['texts' => ['en' => ['operator_joined' => ':name is here to help']]]);

        $this->assertSame('Leyla is here to help', $this->widget->fresh()->text('operator_joined', 'en', ['name' => 'Leyla']));
        $this->assertSame('Leyla söhbətə qoşuldu', $this->widget->fresh()->text('operator_joined', 'az', ['name' => 'Leyla']));
    }
}
