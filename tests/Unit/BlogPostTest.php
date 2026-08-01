<?php

namespace Tests\Unit;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlogPostTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_and_author_relations_resolve(): void
    {
        $category = BlogCategory::factory()->create();
        $author = User::factory()->create();
        $post = BlogPost::factory()->create(['category_id' => $category->id, 'author_id' => $author->id]);

        $this->assertTrue($post->category->is($category));
        $this->assertTrue($post->author->is($author));
    }

    public function test_surviving_category_deletion_nulls_category_id(): void
    {
        $category = BlogCategory::factory()->create();
        $post = BlogPost::factory()->create(['category_id' => $category->id]);

        $category->delete();

        $this->assertNull($post->fresh()->category_id);
    }

    public function test_casts_are_applied(): void
    {
        $post = BlogPost::factory()->create(['is_published' => true, 'views' => 42]);

        $this->assertIsBool($post->fresh()->is_published);
        $this->assertIsInt($post->fresh()->views);
    }
}
