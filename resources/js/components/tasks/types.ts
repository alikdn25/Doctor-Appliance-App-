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
    kind?: TaskKind | null;
    address?: string | null;
    repeat?: 'none' | 'weekly' | 'monthly';
    carried_days?: number;
    automatic?: boolean;
    on_the_way?: string | null;
    job?: { id: number; number: number; customer: string | null } | null;
};

export type TaskKind = 'parts' | 'materials' | 'home' | 'business' | 'call';

export const TASK_KINDS: TaskKind[] = [
    'parts',
    'materials',
    'home',
    'business',
    'call',
];

/** A task read from the To Do microphone (TaskParser): fills a new task's form. */
export type TaskDraft = {
    title: string;
    task_date: string;
    all_day: boolean;
    start_time: string | null;
    address: string | null;
    kind: TaskKind | null;
};

export const REMINDERS = [0, 15, 60, 1440] as const;

/** The three types offered for a new task (owner's task of November 25); Home ("Personal") by default. */
export const TASK_TYPE_CHOICES: TaskKind[] = ['parts', 'home', 'call'];

/** The type's icon on Today: 🛒 shop / parts, 🏠 personal, 📞 call. */
export const taskIcon = (kind: TaskKind | null | undefined) => {
    if (kind === 'parts' || kind === 'materials') return '🛒';
    if (kind === 'call') return '📞';

    return '🏠';
};
