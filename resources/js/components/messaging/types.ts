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
    /** "Open chat" in the SMS Inbox (office). */
    chat_url: string | null;
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

export type ReviewPrompt = {
    url: string;
    mode: SmsMode;
    blocked: string | null;
    phone: string | null;
    profiles: {
        id: number;
        label: string;
        text: string;
        review_url: string;
    }[];
    default_profile_id: number | null;
} | null;
