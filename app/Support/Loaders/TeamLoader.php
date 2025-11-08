<?php

namespace App\Support\Loaders;

use App\Models\TeamMember;
use App\Support\Loaders\Concerns\ResolvesMediaUrls;

class TeamLoader
{
    use ResolvesMediaUrls;

    public static function make(TeamMember $member): array
    {
        return [
            'id' => $member->id,
            'img' => self::mediaUrl($member->photo_path),
            'name' => $member->name,
            'title' => $member->title,
            'social_links' => (array) $member->social_links,
        ];
    }
}
