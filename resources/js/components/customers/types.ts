export type PropertyFormData = {
    label: string;
    line1: string;
    line2: string;
    unit: string;
    city: string;
    province: string;
    postal_code: string;
    country: string;
    access_notes: string;
    gate_code: string;
    site_contact_name: string;
    site_contact_phone: string;
    is_primary: boolean;
};

export type PropertyData = {
    [K in keyof PropertyFormData]: K extends 'is_primary'
        ? boolean
        : K extends 'line1' | 'city' | 'country'
          ? string
          : string | null;
} & { id: number };

export const emptyProperty = (): PropertyFormData => ({
    label: '',
    line1: '',
    line2: '',
    unit: '',
    city: '',
    province: 'BC',
    postal_code: '',
    country: 'CA',
    access_notes: '',
    gate_code: '',
    site_contact_name: '',
    site_contact_phone: '',
    is_primary: false,
});

export const propertyToForm = (p: PropertyData): PropertyFormData => ({
    label: p.label ?? '',
    line1: p.line1,
    line2: p.line2 ?? '',
    unit: p.unit ?? '',
    city: p.city,
    province: p.province ?? '',
    postal_code: p.postal_code ?? '',
    country: p.country,
    access_notes: p.access_notes ?? '',
    gate_code: p.gate_code ?? '',
    site_contact_name: p.site_contact_name ?? '',
    site_contact_phone: p.site_contact_phone ?? '',
    is_primary: p.is_primary,
});

/** Google Maps search link for an address (opens the Maps app on phones). */
export const mapsUrl = (address: string) =>
    `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(address)}`;

export const telUrl = (phone: string) => `tel:${phone.replace(/[^\d+]/g, '')}`;
