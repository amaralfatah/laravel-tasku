<?php

namespace App\Enums;

/**
 * What a member may do inside a workspace, as a capability tier rather than a
 * job title.
 *
 * Super admin sits above all of these and is not a workspace role at all: it
 * lives on `users.is_super_admin` and belongs to no company.
 *
 *   Owner    runs the whole entity
 *   Manager  leads a branch of the org tree
 *   Member   does the work assigned to them
 *   Viewer   reads, and changes nothing
 *
 * Owner and Manager have the same abilities; what separates them is the slice
 * of the org tree those abilities reach, and that comes from the member's own
 * `org_unit_id`. A Member leads nobody and only ever reaches their own tasks.
 *
 * Formal positions — Kepala Divisi, Kepala Sub Divisi, Asisten, ODS — are not
 * roles: they live on `workspace_members.title` as free text the customer
 * shapes, so one codebase serves a freelancer and a holding alike.
 */
enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Member = 'member';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Pemilik',
            self::Manager => 'Manajer',
            self::Member => 'Anggota',
            self::Viewer => 'Pengamat',
        };
    }

    /**
     * What the role is for, one line, shown next to the option when someone
     * hands a role out.
     */
    public function description(): string
    {
        return match ($this) {
            self::Owner => 'Kendali penuh atas ruang kerja dan seluruh strukturnya.',
            self::Manager => 'Memimpin satu cabang struktur: proyek, tugas dan anggotanya.',
            self::Member => 'Mengerjakan tugas yang diberikan dan memperbarui progresnya.',
            self::Viewer => 'Hanya membaca — laporan dan progres, tanpa mengubah apa pun.',
        };
    }

    /**
     * Short badge form, e.g. `OWNER`.
     */
    public function code(): string
    {
        return strtoupper($this->value);
    }

    /**
     * Position on the ladder; 1 is the most senior.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Owner => 1,
            self::Manager => 2,
            self::Member => 3,
            self::Viewer => 4,
        };
    }

    /**
     * The single role at the top of the entity. Its scope is the whole
     * workspace, and a workspace must always keep at least one.
     */
    public function isTop(): bool
    {
        return $this === self::Owner;
    }

    /**
     * Leads other people: org units, membership, projects and monitoring — all
     * of it limited to the leader's own subtree. Owner and Manager reach this
     * bar; Member and Viewer do not.
     */
    public function managesTeam(): bool
    {
        return $this->rank() <= 2;
    }

    /**
     * Whether the role may change anything at all. A Viewer never may, which
     * is the whole point of it: auditors, commissioners and clients watch the
     * work without being able to disturb it.
     */
    public function canWrite(): bool
    {
        return $this !== self::Viewer;
    }

    /**
     * Whether holding this role in a parent workspace reaches the operating
     * companies under it.
     *
     * Only the two group-level roles do: an Owner runs the group, a Viewer
     * reads the consolidated picture. A Manager and a Member belong to the
     * holding's own org tree and have no business inside a subsidiary, which
     * is what keeps one company's data out of another's reach.
     */
    public function reachesSubsidiaries(): bool
    {
        return $this === self::Owner || $this === self::Viewer;
    }

    /**
     * Whether this role sits above another on the ladder. Nobody may hand out
     * a role at or above their own.
     */
    public function outranks(self $other): bool
    {
        return $this->rank() < $other->rank();
    }

    /**
     * Whether this role may hand out another one. Peers are allowed — a
     * Manager may appoint a second one for the same branch — but nobody
     * reaches above their own rank.
     */
    public function mayAssign(self $role): bool
    {
        return $this->canWrite() && ! $role->outranks($this);
    }

    /**
     * Roles this one may invite people as, or promote someone to.
     *
     * @return array<int, self>
     */
    public function assignableRoles(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $role): bool => $this->mayAssign($role),
        ));
    }

    /**
     * The job title a workspace starts someone off with when nobody has typed
     * one. Customers rename these freely; they are data, not behaviour.
     */
    public function defaultTitle(): string
    {
        return $this->label();
    }
}
