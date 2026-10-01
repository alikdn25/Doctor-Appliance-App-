import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

export type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
};

export function PaginationLinks({
    links,
}: {
    links: Paginated<unknown>['links'];
}) {
    if (links.length <= 3) {
        return null;
    }

    return (
        <nav className="mt-4 flex flex-wrap gap-1">
            {links.map((link, i) =>
                link.url ? (
                    <Button
                        key={i}
                        size="sm"
                        variant={link.active ? 'default' : 'outline'}
                        asChild
                    >
                        <Link
                            href={link.url}
                            preserveScroll={false}
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    </Button>
                ) : null,
            )}
        </nav>
    );
}
