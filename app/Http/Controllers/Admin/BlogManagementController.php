<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BlogManagementController extends Controller
{
    // ── Categories ──────────────────────────────────────────────────

    public function categoriesIndex()
    {
        $categories = BlogCategory::withCount('posts')->orderBy('order')->paginate(20);

        return view('admin.blog.categories.index', compact('categories'));
    }

    public function categoriesCreate()
    {
        return view('admin.blog.categories.create');
    }

    public function categoriesStore(Request $request): RedirectResponse
    {
        $validated = $this->validatedCategory($request);
        BlogCategory::create($validated);

        return redirect()->route('admin.blog.categories.index')->with('success', 'Đã tạo chuyên mục.');
    }

    public function categoriesEdit(int $id)
    {
        $category = BlogCategory::findOrFail($id);

        return view('admin.blog.categories.edit', compact('category'));
    }

    public function categoriesUpdate(Request $request, int $id): RedirectResponse
    {
        $category = BlogCategory::findOrFail($id);
        $category->update($this->validatedCategory($request, $category->id));

        return redirect()->route('admin.blog.categories.index')->with('success', 'Đã cập nhật.');
    }

    public function categoriesDestroy(int $id): RedirectResponse
    {
        BlogCategory::findOrFail($id)->delete();

        return redirect()->route('admin.blog.categories.index')->with('success', 'Đã xoá.');
    }

    private function validatedCategory(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:191',
            'slug' => ['required', 'string', 'max:191', Rule::unique('blog_categories', 'slug')->ignore($ignoreId)],
            'description' => 'nullable|string',
            'order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'meta_title' => 'nullable|string|max:191',
            'meta_description' => 'nullable|string|max:500',
            'meta_keywords' => 'nullable|string|max:500',
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $data['order'] = $data['order'] ?? 0;

        return $data;
    }

    // ── Posts ────────────────────────────────────────────────────────

    public function postsIndex(Request $request)
    {
        $query = BlogPost::with('category')->latest();

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        $posts = $query->paginate(20)->appends($request->query());
        $categories = BlogCategory::orderBy('name')->get();

        return view('admin.blog.posts.index', compact('posts', 'categories'));
    }

    public function postsCreate()
    {
        $categories = BlogCategory::orderBy('name')->get();

        return view('admin.blog.posts.create', compact('categories'));
    }

    public function postsStore(Request $request): RedirectResponse
    {
        $validated = $this->validatedPost($request);
        $validated['author_id'] = $request->user()->id;

        if ($validated['is_published'] && empty($validated['published_at'])) {
            $validated['published_at'] = now();
        }

        BlogPost::create($validated);

        return redirect()->route('admin.blog.posts.index')->with('success', 'Đã tạo bài viết.');
    }

    public function postsEdit(int $id)
    {
        $post = BlogPost::findOrFail($id);
        $categories = BlogCategory::orderBy('name')->get();

        return view('admin.blog.posts.edit', compact('post', 'categories'));
    }

    public function postsUpdate(Request $request, int $id): RedirectResponse
    {
        $post = BlogPost::findOrFail($id);
        $validated = $this->validatedPost($request, $post->id);

        if ($validated['is_published'] && empty($post->published_at) && empty($validated['published_at'])) {
            $validated['published_at'] = now();
        }

        $post->update($validated);

        return redirect()->route('admin.blog.posts.index')->with('success', 'Đã cập nhật.');
    }

    public function postsDestroy(int $id): RedirectResponse
    {
        BlogPost::findOrFail($id)->delete();

        return redirect()->route('admin.blog.posts.index')->with('success', 'Đã xoá.');
    }

    private function validatedPost(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'category_id' => 'nullable|exists:blog_categories,id',
            'title' => 'required|string|max:191',
            'slug' => ['required', 'string', 'max:191', Rule::unique('blog_posts', 'slug')->ignore($ignoreId)],
            'excerpt' => 'nullable|string|max:500',
            'content' => 'required|string',
            'featured_image' => 'nullable|string|max:500',
            'is_published' => 'nullable|boolean',
            'published_at' => 'nullable|date',
            'meta_title' => 'nullable|string|max:191',
            'meta_description' => 'nullable|string|max:500',
            'meta_keywords' => 'nullable|string|max:500',
            'og_image' => 'nullable|string|max:500',
        ]);

        $data['is_published'] = $request->boolean('is_published');

        return $data;
    }
}
