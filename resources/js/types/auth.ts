export type User = {
    id: number;
    name: string;
    email: string;
    phone?: string | null;
    avatar?: string;
    is_super_admin: boolean;
    two_factor_enabled?: boolean;
    [key: string]: unknown;
};

export type CurrentCompany = {
    id: number;
    name: string;
    currency: string;
    timezone: string;
};

export type Permissions = {
    viewCustomers?: boolean;
    manageCompany?: boolean;
    viewBrands?: boolean;
    manageTeam?: boolean;
    viewTaxes?: boolean;
};

export type Auth = {
    user: User;
    company: CurrentCompany | null;
    role: { value: string; label: string } | null;
    companies: { id: number; name: string }[];
    can: Permissions;
};

export type Impersonation = {
    userName: string;
    companyName: string | null;
} | null;

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};

export type Option = { value: string; label: string };
