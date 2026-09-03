import { useState } from 'react';

/**
 * The task whose panel is open, seeded from the `task` query parameter a
 * notification links to (NTF-3).
 *
 * Following a second notification while the same view is already mounted keeps
 * the component and its state, so a changed prop has to reopen the panel by
 * itself. Adjusting during render is React's own answer to that, and it is
 * what the board already does to re-sync a drag with the server.
 */
export function useFocusedTask(
    focusTaskId: number | null,
): [number | null, (id: number | null) => void] {
    const [openTaskId, setOpenTaskId] = useState<number | null>(focusTaskId);
    const [seeded, setSeeded] = useState<number | null>(focusTaskId);

    if (focusTaskId !== seeded) {
        setSeeded(focusTaskId);
        setOpenTaskId(focusTaskId);
    }

    return [openTaskId, setOpenTaskId];
}
