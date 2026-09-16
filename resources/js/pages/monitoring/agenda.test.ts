import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { describe, test } from 'node:test';
import { partitionAgendaRows } from './agenda';

type Row = {
    task: {
        id: number;
        status: 'todo' | 'on_hold' | 'done';
    };
};

describe('partitionAgendaRows', () => {
    test('keeps on-hold work visible but outside the active agenda', () => {
        const rows: Row[] = [
            { task: { id: 1, status: 'todo' } },
            { task: { id: 2, status: 'on_hold' } },
            { task: { id: 3, status: 'done' } },
        ];

        const sections = partitionAgendaRows(rows);

        assert.deepEqual(
            sections.active.map((row) => row.task.id),
            [1],
        );
        assert.deepEqual(
            sections.onHold.map((row) => row.task.id),
            [2],
        );
        assert.deepEqual(
            sections.done.map((row) => row.task.id),
            [3],
        );
    });
});

test('groups secondary status sections with a tighter vertical rhythm', async () => {
    const source = await readFile(
        new URL('./focus.tsx', import.meta.url),
        'utf8',
    );

    assert.match(
        source,
        /\{\(onHold\.length > 0 \|\| done\.length > 0 \|\| olderDone > 0\) && \(\s*<div className="overflow-hidden rounded-lg border border-border bg-card">/,
    );
});

test('uses compact Jira-style hierarchy for the monitoring page', async () => {
    const source = await readFile(
        new URL('./focus.tsx', import.meta.url),
        'utf8',
    );
    const groupedSurfaces = source.match(
        /overflow-hidden rounded-lg border border-border bg-card/g,
    );

    assert.equal(groupedSurfaces?.length, 2);
    assert.match(
        source,
        /<h1 className="text-2xl font-semibold tracking-tight">\s*Task saya\s*<\/h1>/,
    );
    assert.match(source, /className="border-b border-border last:border-b-0"/);
    assert.doesNotMatch(source, /sm:text-3xl/);
});
