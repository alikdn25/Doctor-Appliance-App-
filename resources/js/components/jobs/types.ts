export type JobRow = {
    id: number;
    number: number;
    status: string;
    status_label: string;
    job_type_label: string;
    visit_type: string;
    visit_type_label: string;
    outcome: string | null;
    outcome_label: string | null;
    brand: string | null;
    customer: string | null;
    address: string | null;
    appliances: string[];
    visit: {
        scheduled_start: string;
        scheduled_end: string;
        strict_arrival: boolean;
        assignees: string[];
    } | null;
};

export type ApplianceItem = {
    id: number;
    type: string;
    type_label: string;
    manufacturer: string | null;
    model_number: string | null;
    serial_number: string | null;
    under_warranty: boolean;
    removed: boolean;
};

export type Visit = {
    id: number;
    status: string;
    status_label: string;
    scheduled_start: string;
    scheduled_end: string;
    date: string;
    start_time: string;
    end_time: string;
    estimated_duration_minutes: number | null;
    on_the_way_at: string | null;
    started_at: string | null;
    finished_at: string | null;
    minutes_on_job: number | null;
    assignees: { id: number; name: string }[];
    strict_arrival: boolean;
    is_mine: boolean;
};

export type Assignable = { id: number; name: string; role: string };

export const applianceTitle = (a: {
    manufacturer: string | null;
    type_label: string;
}) => [a.manufacturer, a.type_label].filter(Boolean).join(' ');
