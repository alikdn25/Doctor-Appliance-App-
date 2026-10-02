export type DocumentKind = 'estimate' | 'invoice';

export type DocumentRow = {
    id: number;
    kind: DocumentKind;
    number: string;
    status: string;
    status_label: string;
    issued_on: string;
    total: number;
    balance: number | null;
    job_id: number;
    customer: string | null;
};

export type DocumentItem = {
    id: number;
    description: string;
    quantity: string;
    unit_price: number;
    taxable: boolean;
    total: number;
};

export type DocumentTax = {
    tax_rate_id: number | null;
    name: string;
    rate: string;
    amount: number;
};

export type PaymentData = {
    id: number;
    amount: number;
    method: string;
    method_label: string;
    reference: string | null;
    note: string | null;
    received_at: string | null;
    user: string | null;
    provider: string | null;
    voided_at: string | null;
    voided_by: string | null;
    void_reason: string | null;
};

export type JobSummary = {
    id: number;
    number: number;
    customer: string | null;
    address: string | null;
    brand: string | null;
};

export type BillingDocument = DocumentRow & {
    discount_type: 'amount' | 'percent' | null;
    discount_value: string;
    subtotal: number;
    discount_total: number;
    tax_total: number;
    taxes: DocumentTax[];
    notes: string | null;
    items: DocumentItem[];
    job: JobSummary;
    customer: {
        id: number;
        display_name: string;
        phone: string | null;
        email: string | null;
    };
    address: string | null;
    brand: string | null;
    created_by: string | null;
    created_at: string | null;
    // Estimate
    valid_until?: string | null;
    approved_at?: string | null;
    declined_at?: string | null;
    invoice?: { id: number; number: string } | null;
    // Invoice
    due_on?: string | null;
    amount_paid?: number;
    paid_at?: string | null;
    voided_at?: string | null;
    voided_by?: string | null;
    void_reason?: string | null;
    estimate?: { id: number; number: string } | null;
    payments?: PaymentData[];
};

export type TaxOption = {
    id: number;
    name: string;
    rate: string;
    is_default: boolean;
};
