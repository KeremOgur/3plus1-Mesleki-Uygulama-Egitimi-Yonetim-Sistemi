<?php
namespace Tests\Feature;

use App\Domain\{Maintenance,Outbox,Records};
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\Fixture as F;
use Tests\TestCase;

class QueueSchedulerRegressionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_outbox_is_persistent_before_delivery_and_delivery_is_idempotent(): void
    {
        $g=F::graph();$outbox=app(Outbox::class);
        $outbox->record($g['app'],'qa_regression','Sentetik öğrenci bildirimi');
        $event=Records::query('outbox_events')->where('aggregate_id',$g['app']->id)->where('event_type','qa_regression')->firstOrFail();
        $this->assertSame('bekliyor',$event->state);$this->assertNull($event->processed_at);
        $this->assertSame(0,Records::query('notifications')->where('event_key',$event->id)->count());
        $outbox->deliver();
        $this->assertSame('islendi',$event->refresh()->state);$this->assertNotNull($event->processed_at);
        $this->assertSame(1,Records::query('notifications')->where('event_key',$event->id)->where('recipient_id',$g['u']->id)->count());
        $outbox->deliver();
        $this->assertSame(1,Records::query('notifications')->where('event_key',$event->id)->where('recipient_id',$g['u']->id)->count());
    }

    public function test_scheduled_maintenance_emits_one_reminder_per_day_and_preserves_hold(): void
    {
        $g=F::graph();$g['protocol']->valid_until=today()->addDays(10)->toDateString();$g['protocol']->save();
        $g['student']->graduated_on=today()->toDateString();$g['student']->save();
        $g['doc']->student_id=$g['student']->id;$g['doc']->legal_hold=true;$g['doc']->save();
        app(Maintenance::class)->run();app(Maintenance::class)->run();
        $this->assertSame(1,Records::query('outbox_events')->where('aggregate_id',$g['protocol']->id)->where('event_type','hatirlatma:protokol:'.today()->toDateString())->count());
        $this->assertTrue($g['doc']->refresh()->legal_hold);
        $this->assertSame(today()->addYears(5)->toDateString(),$g['doc']->retention_until);
        $this->assertGreaterThanOrEqual(today()->addYears(5)->timestamp,\Carbon\Carbon::parse($g['student']->refresh()->retained_until)->timestamp);
    }

    public function test_scheduler_registers_outbox_maintenance_backup_and_health(): void
    {
        $events=app(Schedule::class)->events();
        foreach(['mue:outbox'=>'* * * * *','mue:maintenance'=>'0 * * * *','mue:backup'=>'0 2 * * *','mue:health'=>'0 * * * *'] as $command=>$expression){
            $matches=array_values(array_filter($events,fn($e)=>str_contains($e->command??'',$command)));
            $this->assertCount(1,$matches);$this->assertSame($expression,$matches[0]->expression);$this->assertTrue($matches[0]->withoutOverlapping);
        }
    }
}
