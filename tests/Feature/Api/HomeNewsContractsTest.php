<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Comment;
use App\Models\Gallery;
use App\Models\Like;
use App\Models\News;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeNewsContractsTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_news_index_returns_normalized_loader_payload(): void
    {
        $creator = User::factory()->create();
        $reader = User::factory()->create();

        $news = News::query()->create([
            'creator_id' => $creator->id,
            'headline' => 'New Branch Opening',
            'slug' => 'new-branch-opening',
            'summary' => 'Summary text',
            'content' => 'Detailed body text',
            'status' => 'active',
            'published_at' => now()->subDay(),
            'meta' => ['page' => 'press'],
        ]);

        News::query()->create([
            'creator_id' => $creator->id,
            'headline' => 'Draft Note',
            'slug' => 'draft-note',
            'summary' => 'Should not be visible',
            'content' => 'Should not be visible',
            'status' => 'draft',
            'published_at' => now()->subDay(),
        ]);

        $tag = Tag::query()->create([
            'creator_id' => $creator->id,
            'name' => 'Announcement',
            'slug' => 'announcement',
        ]);
        $news->tags()->sync([$tag->id]);

        $category = Category::query()->create([
            'creator_id' => $creator->id,
            'name' => 'Updates',
            'slug' => 'updates',
            'status' => 'active',
        ]);
        $news->categories()->sync([$category->id]);

        Gallery::query()->create([
            'creator_id' => $creator->id,
            'model_id' => $news->id,
            'model_type' => News::class,
            'disk' => 'public',
            'path' => 'uploads/news/new-branch.jpg',
        ]);

        Like::query()->create([
            'creator_id' => $reader->id,
            'model_id' => $news->id,
            'model_type' => News::class,
        ]);

        Comment::query()->create([
            'user_id' => $reader->id,
            'model_id' => $news->id,
            'model_type' => News::class,
            'comment' => 'Great announcement.',
            'status' => 'published',
            'rating' => 5,
        ]);
        Comment::query()->create([
            'user_id' => $reader->id,
            'model_id' => $news->id,
            'model_type' => News::class,
            'comment' => 'Pending moderation.',
            'status' => 'pending',
            'rating' => 3,
        ]);

        $response = $this->getJson('/api/home/news');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $news->id)
            ->assertJsonPath('data.0.title', 'New Branch Opening')
            ->assertJsonPath('data.0.headline', 'New Branch Opening')
            ->assertJsonPath('data.0.slug', 'new-branch-opening')
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath('data.0.page', 'press')
            ->assertJsonPath('data.0.likes_count', 1)
            ->assertJsonPath('data.0.approved_comments_count', 1);

        $payload = (array) $response->json('data.0');
        $this->assertContains('Announcement', $payload['tags']);
        $this->assertContains('Updates', $payload['categories']);
        $this->assertNotEmpty($payload['img']);
    }

    public function test_home_news_single_returns_normalized_comments_only_for_visible_statuses(): void
    {
        $creator = User::factory()->create();
        $reader = User::factory()->create();

        $news = News::query()->create([
            'creator_id' => $creator->id,
            'headline' => 'Factory Update',
            'slug' => 'factory-update',
            'summary' => 'Factory summary',
            'content' => 'Factory content',
            'status' => 'active',
            'published_at' => now()->subHours(2),
        ]);

        Comment::query()->create([
            'user_id' => $reader->id,
            'model_id' => $news->id,
            'model_type' => News::class,
            'comment' => 'Published comment',
            'status' => 'published',
            'rating' => 4,
        ]);
        Comment::query()->create([
            'user_id' => $reader->id,
            'model_id' => $news->id,
            'model_type' => News::class,
            'comment' => 'Hidden comment',
            'status' => 'pending',
            'rating' => 2,
        ]);

        $response = $this->getJson("/api/home/news/{$news->slug}");
        $response
            ->assertOk()
            ->assertJsonPath('id', $news->id)
            ->assertJsonPath('headline', 'Factory Update')
            ->assertJsonPath('title', 'Factory Update')
            ->assertJsonPath('comments_count', 1)
            ->assertJsonPath('approved_comments_count', 1)
            ->assertJsonPath('comments.0.comment', 'Published comment');

        $comments = collect($response->json('comments'))->pluck('comment');
        $this->assertFalse($comments->contains('Hidden comment'));
    }
}
