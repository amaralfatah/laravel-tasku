<?php

namespace App\Support;

use App\Models\User;

/**
 * Mentions are stored as `@[user:42]` and rendered with the user's current
 * name (CMT-4), so a rename does not leave stale text behind in old comments.
 */
class MentionParser
{
    public const PATTERN = '/@\[user:(\d+)\]/';

    /**
     * User ids mentioned in a comment body.
     *
     * @return array<int, int>
     */
    public static function ids(string $body): array
    {
        preg_match_all(self::PATTERN, $body, $matches);

        return array_values(array_unique(array_map('intval', $matches[1])));
    }

    /**
     * Names for the mentioned users, so the frontend can render them.
     *
     * Only users the comment's task is actually shared with should ever be
     * mentioned, so the lookup is restricted to the ids passed in.
     *
     * @param  array<int, int>  $allowedIds
     * @return array<int, string> name keyed by user id
     */
    public static function names(string $body, array $allowedIds = []): array
    {
        $ids = self::ids($body);

        if ($ids === []) {
            return [];
        }

        if ($allowedIds !== []) {
            $ids = array_values(array_intersect($ids, $allowedIds));
        }

        return User::query()
            ->whereIn('id', $ids)
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Drop mentions of users outside the given set, so a hand-crafted body
     * cannot leak the name of someone who is not on the project.
     *
     * @param  array<int, int>  $allowedIds
     */
    public static function sanitize(string $body, array $allowedIds): string
    {
        return (string) preg_replace_callback(
            self::PATTERN,
            fn (array $match): string => in_array((int) $match[1], $allowedIds, true)
                ? $match[0]
                : '@?',
            $body,
        );
    }
}
