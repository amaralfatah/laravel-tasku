import { STATUS_CATEGORY } from '@/types/tasks';
import type { TaskStatus } from '@/types/tasks';

type AgendaRow = {
    task: {
        status: TaskStatus;
    };
};

export function partitionAgendaRows<Row extends AgendaRow>(
    rows: Row[],
): {
    active: Row[];
    onHold: Row[];
    done: Row[];
} {
    const active: Row[] = [];
    const onHold: Row[] = [];
    const done: Row[] = [];

    for (const row of rows) {
        if (row.task.status === 'on_hold') {
            onHold.push(row);
        } else if (STATUS_CATEGORY[row.task.status] === 'done') {
            done.push(row);
        } else {
            active.push(row);
        }
    }

    return { active, onHold, done };
}
