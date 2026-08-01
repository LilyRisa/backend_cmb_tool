<?php

namespace Tests\Unit;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlogCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_posts_relation_returns_posts_in_the_category(): void
    {
        $category = BlogCategory::factory()->create();
        BlogPost::factory()->count(2)->create(['category_id' => $category->id]);
        BlogPost::factory()->create();

        $this->assertCount(2, $category->posts);
    }
}
