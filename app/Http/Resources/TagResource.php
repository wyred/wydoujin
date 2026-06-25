<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** JSON shape of a normalized tag. / 正規化タグのAPI表現。 */
class TagResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'value' => $this->value,
            'works_count' => $this->whenCounted('works'),
        ];
    }
}
