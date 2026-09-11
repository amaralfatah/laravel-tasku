/**
 * Today in the viewer's own timezone, as the `yyyy-mm-dd` a date input wants.
 * `toISOString` would hand back UTC, which reads as yesterday east of it.
 */
export function today(): string {
    const now = new Date();

    return [
        now.getFullYear(),
        String(now.getMonth() + 1).padStart(2, '0'),
        String(now.getDate()).padStart(2, '0'),
    ].join('-');
}
