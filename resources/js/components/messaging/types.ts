export type SmsMode = 'automatic' | 'technician_phone' | 'off';

export type MessageItem = {
    id: number;
    direction: 'outbound' | 'inbound';
    channel: 'sms' | 'email' | 'technician_phone';
    channel_label: string;
    kind_label: string;
    body: string;
    status: string;
    status_label: string;
    status_reason: string | null;
    to: string | null;
    from: string | null;
    user: string | null;
    at: string | null;
    job_id: number | null;
};

export type JobMessaging = {
    mode: SmsMode;
    phone: string | null;
    opted_out: boolean;
    sms_blocked: string | null;
    texts: { general: string; on_my_way: string };
    messages: MessageItem[];
};

export type DocumentSms = {
    mode: SmsMode;
    phone: string | null;
    blocked: string | null;
    text: string;
    url: string;
    opened_url: string;
    kind: string;
} | null;
