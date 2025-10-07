<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class CommentController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:comment.release')->only('release');
        $this->middleware('permission:comment.answer')->only('answer');
        $this->middleware('permission:comment.specialize')->only('specialize');
    }

    #[OA\Post(
        path: '/api/admin/comments/{comment}/release',
        operationId: 'adminCommentsRelease',
        summary: 'Publish a comment',
        security: [['sanctum' => []]],
        tags: ['Admin - Comments'],
        parameters: [
            new OA\Parameter(name: 'comment', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Comment published', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function release(Comment $comment): JsonResponse
    {
        $comment->update(['status' => 'published']);

        return response()->json($comment->fresh());
    }

    #[OA\Post(
        path: '/api/admin/comments/{comment}/answer',
        operationId: 'adminCommentsAnswer',
        summary: 'Answer a comment',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['answer'],
                properties: [
                    new OA\Property(property: 'answer', type: 'string'),
                ]
            )
        ),
        tags: ['Admin - Comments'],
        parameters: [
            new OA\Parameter(name: 'comment', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Comment answered', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function answer(Request $request, Comment $comment): JsonResponse
    {
        $data = $request->validate([
            'answer' => ['required', 'string'],
        ]);

        $comment->update([
            'answer' => $data['answer'],
            'admin_id' => $request->user()?->id,
            'status' => 'answered',
        ]);

        return response()->json($comment->fresh()->load(['user', 'admin']));
    }

    #[OA\Post(
        path: '/api/admin/comments/{comment}/specialize',
        operationId: 'adminCommentsSpecialize',
        summary: 'Mark comment as special',
        security: [['sanctum' => []]],
        tags: ['Admin - Comments'],
        parameters: [
            new OA\Parameter(name: 'comment', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Comment specialized', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function specialize(Comment $comment): JsonResponse
    {
        $comment->update(['status' => 'special']);

        return response()->json($comment->fresh());
    }
}
