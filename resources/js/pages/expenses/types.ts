export type ExpenseCategory = {
    id: number;
    name: string;
    is_active: boolean;
    can_update: boolean;
    totals?: { currency: string; price: number; tax: number; total: number }[];
};

export type ExpenseRow = {
    id: number;
    category_id: number;
    service_job_id: number | null;
    category: string;
    spent_on: string;
    description: string;
    merchant: string | null;
    amount: number;
    tax_amount: number;
    taxes:
        | {
              tax_rate_id: number;
              name: string;
              rate: string;
              compound: boolean;
              amount: number;
          }[]
        | null;
    total: number;
    currency: string;
    creator: string | null;
    receipt_url: string | null;
    receipt_name: string | null;
    can_update: boolean;
    can_delete: boolean;
    notes?: string | null;
};

/** A job an expense can be for: "#1005 · Jane Smith". */
export type ExpenseJob = {
    id: number;
    number: number;
    customer: string | null;
};
