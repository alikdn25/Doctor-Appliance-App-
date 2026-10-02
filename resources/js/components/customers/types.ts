export type PropertyFormData = {
    label: string;
    line1: string;
    line2: string;
    unit: string;
    city: string;
    region: string;
    postal_code: string;
    country: string;
    access_notes: string;
    gate_code: string;
    site_contact_name: string;
    site_contact_phone: string;
    is_primary: boolean;
    /** Set when the address was picked from Google Places suggestions. */
    google_place_id: string;
    latitude: string;
    longitude: string;
};

export type PropertyData = {
    [K in keyof PropertyFormData]: K extends 'is_primary'
        ? boolean
        : K extends 'line1' | 'city' | 'country'
          ? string
          : string | null;
} & { id: number };

/** A new address in the company's country. */
export const emptyProperty = (country: string): PropertyFormData => ({
    label: '',
    line1: '',
    line2: '',
    unit: '',
    city: '',
    region: '',
    postal_code: '',
    country,
    access_notes: '',
    gate_code: '',
    site_contact_name: '',
    site_contact_phone: '',
    is_primary: false,
    google_place_id: '',
    latitude: '',
    longitude: '',
});

/** Edit form values; the stored E.164 phone is shown in the company's national format. */
export const propertyToForm = (
    p: PropertyData,
    phoneText: (value: string | null) => string = (v) => v ?? '',
): PropertyFormData => ({
    label: p.label ?? '',
    line1: p.line1,
    line2: p.line2 ?? '',
    unit: p.unit ?? '',
    city: p.city,
    region: p.region ?? '',
    postal_code: p.postal_code ?? '',
    country: p.country,
    access_notes: p.access_notes ?? '',
    gate_code: p.gate_code ?? '',
    site_contact_name: p.site_contact_name ?? '',
    site_contact_phone: phoneText(p.site_contact_phone),
    is_primary: p.is_primary,
    google_place_id: p.google_place_id ?? '',
    latitude: p.latitude ?? '',
    longitude: p.longitude ?? '',
});

/** Google Maps search link for an address (opens the Maps app on phones). */
export const mapsUrl = (address: string) =>
    `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(address)}`;

export const telUrl = (phone: string) => `tel:${phone.replace(/[^\d+]/g, '')}`;
