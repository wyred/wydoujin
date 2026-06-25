<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape of a work for the API. Tags are grouped by type so an LLM sees the
 * metadata model at a glance. / 作品のAPI表現（タグは型別にまとめる）。
 */
class WorkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'content_hash' => $this->content_hash,
            'filename' => $this->filename,
            'title' => $this->title,
            'title_raw' => $this->title_raw,
            'page_count' => (int) $this->page_count,
            'is_missing' => (bool) $this->is_missing,
            'tags_locked' => (bool) $this->tags_locked,
            'series_locked' => (bool) $this->series_locked,
            'mangaka' => $this->whenLoaded('mangaka', fn () => [
                'id' => $this->mangaka->id,
                'name' => $this->mangaka->name,
                'slug' => $this->mangaka->slug,
            ]),
            'series' => $this->whenLoaded('series', fn () => $this->series ? [
                'id' => $this->series->id,
                'name' => $this->series->name,
            ] : null),
            'tags' => $this->whenLoaded('tags', fn () => $this->tags
                ->groupBy('type')
                ->map(fn ($group) => $group
                    ->map(fn ($tag) => ['id' => $tag->id, 'value' => $tag->value])
                    ->values())),
            'progress' => $this->whenLoaded('readingProgress', fn () => $this->readingProgress ? [
                'current_page' => (int) $this->readingProgress->current_page,
                'is_completed' => (bool) $this->readingProgress->is_completed,
            ] : null),
        ];
    }
}
