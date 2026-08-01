<?php

namespace Tests\Feature\Admin;

use App\Models\ContactMessage;
use App\Models\Preorder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InquiryManagementControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): self
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        return $this;
    }

    public function test_contact_messages_index_lists_messages(): void
    {
        ContactMessage::factory()->count(3)->create();

        $response = $this->actingAsAdmin()->get('/admin/contact-messages');

        $response->assertOk();
        $response->assertViewHas('messages', fn ($m) => $m->total() === 3);
    }

    public function test_contact_messages_update_status_changes_status_and_notes(): void
    {
        $message = ContactMessage::factory()->create(['status' => 'new']);

        $response = $this->actingAsAdmin()->put("/admin/contact-messages/{$message->id}", [
            'status' => 'resolved',
            'admin_notes' => 'Called back, resolved.',
        ]);

        $response->assertRedirect(route('admin.contact-messages.index'));
        $this->assertEquals('resolved', $message->fresh()->status);
        $this->assertEquals('Called back, resolved.', $message->fresh()->admin_notes);
    }

    public function test_preorders_index_lists_preorders(): void
    {
        Preorder::factory()->count(2)->create();

        $this->actingAsAdmin()->get('/admin/preorders')->assertOk();
    }

    public function test_preorders_update_status_changes_status(): void
    {
        $preorder = Preorder::factory()->create(['status' => 'pending']);

        $response = $this->actingAsAdmin()->put("/admin/preorders/{$preorder->id}", ['status' => 'contacted']);

        $response->assertRedirect(route('admin.preorders.index'));
        $this->assertEquals('contacted', $preorder->fresh()->status);
    }

    public function test_contact_messages_index_rejects_unauthenticated_requests(): void
    {
        $this->get('/admin/contact-messages')->assertRedirect();
    }
}
