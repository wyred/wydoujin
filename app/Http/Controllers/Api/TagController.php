<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use Illuminate\Http\Request;

/** Read endpoint for the canonical tag vocabulary. / 正規タグ一覧API。 */
final class TagController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'type' => ['nullable', 'string', 'in:'.implode(',', Tag::TYPES)],
        ]);

        $q = trim((string) $request->query('q', ''));

        $tags = Tag::query()->canonical()
            ->whereHas('works')
            ->withCount('works')
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->query('type')))
            ->when($q !== '', function ($query) use ($q): void {
                $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';
                $query->whereRaw("value LIKE ? ESCAPE '!'", [$term]);
            })
            ->orderBy('type')->orderBy('sort_value')
            ->paginate(100);

        return TagResource::collection($tags);
    }
}
