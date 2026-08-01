<?php

namespace Tests\Feature\Admin;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlogManagementControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): self
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        return $this;
    }

    public function test_category_store_creates_a_category(): void
    {
        $response = $this->actingAsAdmin()->post('/admin/blog/categories', [
            'name' => 'Marketing Tips',
            'slug' => 'marketing-tips',
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('admin.blog.categories.index'));
        $this->assertDatabaseHas('blog_categories', ['slug' => 'marketing-tips']);
    }

    public function test_category_index_lists_categories(): void
    {
        BlogCategory::factory()->count(2)->create();

        $this->actingAsAdmin()->get('/admin/blog/categories')->assertOk();
    }

    public function test_post_store_creates_a_post_and_sets_author_to_current_admin(): void
    {
        $category = BlogCategory::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->post('/admin/blog/posts', [
            'category_id' => $category->id,
            'title' => 'How to automate video marketing',
            'slug' => 'how-to-automate-video-marketing',
            'content' => 'Full article content here.',
            'is_published' => '1',
        ]);

        $response->assertRedirect(route('admin.blog.posts.index'));
        $this->assertDatabaseHas('blog_posts', ['slug' => 'how-to-automate-video-marketing', 'author_id' => $admin->id]);
    }

    public function test_post_store_validates_required_fields(): void
    {
        $this->actingAsAdmin()
            ->post('/admin/blog/posts', ['title' => 'X'])
            ->assertSessionHasErrors(['slug', 'content']);
    }

    public function test_post_update_edits_an_existing_post(): void
    {
        $post = BlogPost::factory()->create(['title' => 'Old title']);

        $response = $this->actingAsAdmin()->put("/admin/blog/posts/{$post->id}", [
            'category_id' => $post->category_id,
            'title' => 'New title',
            'slug' => $post->slug,
            'content' => $post->content,
            'is_published' => '0',
        ]);

        $response->assertRedirect(route('admin.blog.posts.index'));
        $this->assertEquals('New title', $post->fresh()->title);
    }

    public function test_post_index_rejects_unauthenticated_requests(): void
    {
        $this->get('/admin/blog/posts')->assertRedirect();
    }
}
