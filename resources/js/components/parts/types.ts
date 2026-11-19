import type { Option } from '@/types';

/** A part as the Parts page and the job page get it (PartsController::present). */
export type PartItem = {
    id: number;
    name: string;
    part_number: string | null;
    number_unverified: boolean;
    quantity: number;
    supplier_id: string | null;
    supplier: string | null;
    status: 'to_order' | 'ordered' | 'on_hand';
    ordered_on: string | null;
    days_waiting: number | null;
    long_wait: boolean;
    job: {
        id: number;
        number: number;
        status: string;
        customer: string | null;
        appliance: string | null;
    };
    can_edit: boolean;
};

export type SupplierOption = Option & { address: string | null };
