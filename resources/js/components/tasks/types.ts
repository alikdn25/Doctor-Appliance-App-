/** A task as the calendar and My Jobs get it (TaskController::present). */
export type TaskItem = {
    id: number;
    title: string;
    note: string | null;
    date: string;
    all_day: boolean;
    start_time: string | null;
    end_time: string | null;
    remind_minutes: number | null;
    user_id: number;
    user_name: string | null;
    created_by: string | null;
    done: boolean;
    can_edit: boolean;
};

export const REMINDERS = [0, 15, 60, 1440] as const;
