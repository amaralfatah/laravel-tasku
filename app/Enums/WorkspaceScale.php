<?php

namespace App\Enums;

use App\Models\Workspace;
use App\Models\WorkspaceMember;

/**
 * How much organisation a workspace actually has, read off its own data.
 *
 * The point is progressive disclosure: one schema and one codebase serve a
 * freelancer and a multi-entity group, but a freelancer must never be shown
 * the group machinery. Nobody picks this — it follows from what exists, so a
 * workspace grows into the fuller interface as its structure appears:
 *
 *   Solo      one person, no org tree
 *   Team      several people, still flat
 *   Company   an org tree with real depth
 *   Holding   runs other workspaces
 *
 * Deliberately derived rather than stored: a setting would drift from the
 * data, and there would then be two answers to the same question.
 */
enum WorkspaceScale: string
{
    case Solo = 'solo';
    case Team = 'team';
    case Company = 'company';
    case Holding = 'holding';

    /**
     * Members above which a flat team is treated as a company even without an
     * org tree — at that size people are looking for structure anyway.
     */
    public const COMPANY_HEADCOUNT = 20;

    public static function of(Workspace $workspace): self
    {
        if ($workspace->isHolding()) {
            return self::Holding;
        }

        $members = WorkspaceMember::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->count();

        // Depth of the slice the workspace runs: a root on its own is flat,
        // anything below it is an org chart someone drew on purpose.
        $hasTree = $workspace->orgUnits()->where('depth', '>', $workspace->rootOrgUnit?->depth ?? 0)->exists();

        return match (true) {
            $hasTree, $members > self::COMPANY_HEADCOUNT => self::Company,
            $members > 1 => self::Team,
            default => self::Solo,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Solo => 'Perorangan',
            self::Team => 'Tim',
            self::Company => 'Perusahaan',
            self::Holding => 'Holding',
        };
    }

    /**
     * Whether the org tree, the roster and the reporting pages are worth
     * showing. A solo workspace has nobody to place and nobody to monitor.
     */
    public function showsOrganisation(): bool
    {
        return $this !== self::Solo;
    }

    /**
     * Whether the consolidated group dashboard applies.
     */
    public function showsGroup(): bool
    {
        return $this === self::Holding;
    }
}
