import { Head, Link } from '@inertiajs/react';
import { MailX } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { login } from '@/routes';

export default function InvalidInvitation() {
    return (
        <>
            <Head title="Undangan tidak berlaku" />

            <div className="flex min-h-dvh items-center justify-center p-6">
                <div className="w-full max-w-md space-y-6 text-center">
                    <div className="mx-auto flex size-12 items-center justify-center rounded-xl bg-muted">
                        <MailX
                            className="size-6 text-muted-foreground"
                            aria-hidden="true"
                        />
                    </div>

                    <div className="space-y-2">
                        <h1 className="text-xl font-semibold">
                            Undangan tidak berlaku
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Tautan undangan sudah kedaluwarsa, sudah dipakai,
                            atau dibatalkan. Minta admin perusahaan Anda
                            mengirim undangan baru.
                        </p>
                    </div>

                    <Button variant="outline" asChild>
                        <Link href={login()}>Ke halaman masuk</Link>
                    </Button>
                </div>
            </div>
        </>
    );
}
