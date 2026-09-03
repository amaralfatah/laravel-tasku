<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Splits the formal position off the capability tier.
 *
 * `role` used to carry a job title from one company's ladder (BOD-1 Kepala
 * Divisi through BOD-4 ODS), which locked the product to that org chart. It
 * now carries a generic tier, and the title people actually read moves to its
 * own column where every customer shapes it themselves.
 *
 * BOD-2 and BOD-3 both become Manager: they always had identical abilities and
 * differed only by the slice of the org tree their own unit gave them, which is
 * unchanged by this.
 */
return new class extends Migration
{
    /**
     * Old value to new tier.
     *
     * @var array<string, string>
     */
    protected array $tiers = [
        'bod_1' => 'owner',
        'bod_2' => 'manager',
        'bod_3' => 'manager',
        'bod_4' => 'member',
    ];

    /**
     * Old value to the job title it stood for.
     *
     * @var array<string, string>
     */
    protected array $titles = [
        'bod_1' => 'Kepala Divisi',
        'bod_2' => 'Kepala Sub Divisi',
        'bod_3' => 'Asisten',
        'bod_4' => 'ODS / Programmer',
    ];

    public function up(): void
    {
        Schema::table('workspace_members', function (Blueprint $table): void {
            $table->string('title', 100)->nullable()->after('role');
            $table->string('role')->default('member')->change();
        });

        Schema::table('invitations', function (Blueprint $table): void {
            $table->string('role')->default('member')->change();
        });

        foreach ($this->tiers as $old => $tier) {
            DB::table('workspace_members')
                ->where('role', $old)
                ->update(['role' => $tier, 'title' => $this->titles[$old]]);

            DB::table('invitations')->where('role', $old)->update(['role' => $tier]);
        }
    }

    public function down(): void
    {
        // The two manager tiers collapsed into one, so the title column is the
        // only way back to the original rung.
        foreach ($this->tiers as $old => $tier) {
            DB::table('workspace_members')
                ->where('role', $tier)
                ->where('title', $this->titles[$old])
                ->update(['role' => $old]);

            DB::table('invitations')->where('role', $tier)->update(['role' => $old]);
        }

        Schema::table('workspace_members', function (Blueprint $table): void {
            $table->dropColumn('title');
            $table->string('role')->default('bod_4')->change();
        });

        Schema::table('invitations', function (Blueprint $table): void {
            $table->string('role')->default('bod_4')->change();
        });
    }
};
