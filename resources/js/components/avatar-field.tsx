import { Trash2, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';

/**
 * Image picker for a profile photo or a workspace logo.
 *
 * Posts either a new file under `field` or `remove_<field>=1`; the preview is
 * a local object URL so the change is visible before the form is submitted.
 * A logo is square because a company mark is drawn to its own edges, while a
 * person keeps the round frame the rest of the app shows them in.
 */
export function AvatarField({
    name,
    currentUrl,
    field = 'avatar',
    shape = 'circle',
    label = 'Unggah foto',
    alt,
}: {
    name: string;
    currentUrl?: string | null;
    field?: string;
    shape?: 'circle' | 'square';
    label?: string;
    alt?: string;
}) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [preview, setPreview] = useState<string | null>(null);
    const [removed, setRemoved] = useState(false);
    const getInitials = useInitials();

    const shownUrl =
        preview ?? (removed ? undefined : (currentUrl ?? undefined));
    const hasImage = Boolean(shownUrl);
    const radius = shape === 'circle' ? 'rounded-full' : 'rounded-md';

    return (
        <div className="flex items-center gap-4">
            <Avatar className={cn('size-16', radius)}>
                <AvatarImage
                    src={shownUrl}
                    alt={alt ?? `Foto profil ${name}`}
                />
                <AvatarFallback
                    className={cn('bg-muted text-base font-medium', radius)}
                >
                    {getInitials(name)}
                </AvatarFallback>
            </Avatar>

            <div className="space-y-2">
                <div className="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => inputRef.current?.click()}
                    >
                        <Upload className="size-4" aria-hidden="true" />
                        {label}
                    </Button>

                    {hasImage && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="text-destructive hover:text-destructive"
                            onClick={() => {
                                setPreview(null);
                                setRemoved(true);

                                if (inputRef.current) {
                                    inputRef.current.value = '';
                                }
                            }}
                        >
                            <Trash2 className="size-4" aria-hidden="true" />
                            Hapus
                        </Button>
                    )}
                </div>

                <p className="text-xs text-muted-foreground">
                    JPG, PNG, atau WEBP. Maksimal 2 MB.
                </p>
            </div>

            <input
                ref={inputRef}
                type="file"
                name={field}
                accept="image/jpeg,image/png,image/webp"
                className="sr-only"
                aria-label={`Pilih file ${label.toLowerCase()}`}
                onChange={(event) => {
                    const file = event.target.files?.[0];
                    setPreview(file ? URL.createObjectURL(file) : null);
                    setRemoved(false);
                }}
            />

            {removed && (
                <input type="hidden" name={`remove_${field}`} value="1" />
            )}
        </div>
    );
}
