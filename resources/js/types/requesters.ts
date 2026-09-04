/**
 * Whoever asked for the work — a client, a stakeholder, the head of another
 * division. Not the same as the task's reporter, who is the user that filed
 * it, and usually not a user of the application at all.
 */
export type RequesterOption = {
    id: number;
    name: string;
    /** Where they ask from: a department, a client company. */
    organization: string | null;
};

/** A row of the management page, which sees more than the picker does. */
export type RequesterRow = RequesterOption & {
    email: string | null;
    is_active: boolean;
    /** Tasks naming this requester. Anything above zero blocks deletion. */
    tasks_count: number;
};
