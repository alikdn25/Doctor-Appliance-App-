export type DocumentKind = 'estimate' | 'invoice';

export type DocumentRow = {
    id: number;
    kind: DocumentKind;
    number: string;
    status: string;
    status_label: string;
    issued_on: string;
    currency: string;
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
    tax_rate_ids: number[] | null;
    tax_names: string[];
    total: number;
    /** Estimates: the customer may add it; counted only when selected. */
    optional: boolean;
    selected: boolean;
    kind: 'service' | 'part' | 'material';
    service_id: number | null;
    part_number: string | null;
    unit: string | null;
    bill_to_customer: boolean;
    warranty_value: number | null;
    warranty_unit: string | null;
    warranty_label: string;
    warranty_ends_on: string | null;
    supplier: string | null;
    unit_cost: number | null;
    costs_editable?: boolean;
    private_difference?: number | null;
    supplier_taxes: {
        tax_rate_id: number | null;
        name: string;
        amount: number;
        recoverable: boolean;
    }[];
    total_cost: number | null;
};

export type DocumentTax = {
    tax_rate_id: number | null;
    name: string;
    rate: string;
    compound?: boolean;
    amount: number;
};

export type PaymentData = {
    id: number;
    amount: number;
    /** Tip taken by the provider, not part of the invoice (negative on a refund). */
    tip_amount: number;
    is_refund: boolean;
    currency: string;
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
    refund_reason: string | null;
    processing_fee: number;
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
    prices_include_tax: boolean;
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
    costs_visible?: boolean;
    cost_total?: number | null;
    // Estimate
    valid_until?: string | null;
    expired?: boolean;
    approved_at?: string | null;
    declined_at?: string | null;
    invoice?: { id: number; number: string } | null;
    deposit_type?: 'percent' | 'amount' | null;
    deposit_value?: string;
    deposit_amount?: number;
    deposit_paid?: number;
    online_approval?: {
        signer_name: string;
        signature_type: 'drawn' | 'typed';
        signature: string | null;
        ip: string | null;
    } | null;
    decline_reason?: string | null;
    revision?: number;
    revised_at?: string | null;
    revised_from?: string | null;
    versions?: {
        id: number;
        number: string;
        revision: number;
        status: string;
        status_label: string;
        signer_name: string | null;
        approved_at: string | null;
        revised_at: string | null;
        total: number;
    }[];
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

/** A price book service to fill a line with. Price in the company currency (null = not set). */
export type ServiceOption = {
    id: number;
    name: string;
    category: string | null;
    description: string | null;
    unit_price: number | null;
    currency: string;
    taxable: boolean;
    kind: 'service' | 'part' | 'material';
    part_number: string | null;
    unit: string | null;
    unit_cost: number | null;
    costs_editable?: boolean;
    supplier: string | null;
    warranty_value: number | null;
    warranty_unit: string | null;
};

export type TaxOption = {
    id: number;
    name: string;
    rate: string;
    is_compound: boolean;
    is_default: boolean;
};
