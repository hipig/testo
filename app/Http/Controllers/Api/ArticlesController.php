<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleResource;
use App\Models\Article;
use Illuminate\Http\Request;

class ArticlesController extends Controller
{
    public function index(Request $request)
    {
        $articles = Article::query()
            ->with('category')
            ->inSubject($request->subject_pid)
            ->onlySubject($request->subject_id)
            ->where('type', Article::TYPE_SUBJECT)
            ->status()
            ->order($request->order ?? 'new')
            ->paginate($request->page_size ?? config('api.page_size'));

        return ArticleResource::collection($articles);
    }

    public function hotList(Request $request)
    {
        $articles = Article::query()
            ->where('type', Article::TYPE_SUBJECT)
            ->status()
            ->order('hot')
            ->limit(8)
            ->get();

        return ArticleResource::collection($articles);
    }

    public function show(Request $request, Article $article)
    {
        return ArticleResource::make($article);
    }
}
