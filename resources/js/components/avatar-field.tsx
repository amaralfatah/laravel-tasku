import { Trash2, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { useInitials } from '@/hooks/use-initials';

/**
 * Avatar picker for the profile form.
 *
 * Posts either a new `avatar` file or `remove_avatar=1`; the preview is a
 * local object URL so the change is visible before the form is submitted.
 */
export function AvatarField({
    name,
    currentUrl,
}: {
    name: string;
    currentUrl?: string | null;
}) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [preview, setPreview] = useState<string | null>(null);
    const [removed, setRemoved] = useState(false);
    const getInitials = useInitials();

    const shownUrl =
        preview ?? (removed ? undefined : (currentUrl ?? undefined));
    const hasImage = Boolean(shownUrl);

    return (
        <div className="flex items-center gap-4">
            <Avatar className="size-16 rounded-full">
                <AvatarImage src={shownUrl} alt={`Foto profil ${name}`} />
                <AvatarFallback className="rounded-full bg-muted text-base font-medium">
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
                        Unggah foto
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
                name="avatar"
                accept="image/jpeg,image/png,image/webp"
                className="sr-only"
                aria-label="Pilih file foto profil"
                onChange={(event) => {
                    const file = event.target.files?.[0];
                    setPreview(file ? URL.createObjectURL(file) : null);
                    setRemoved(false);
                }}
            />

            {removed && <input type="hidden" name="remove_avatar" value="1" />}
        </div>
    );
}
