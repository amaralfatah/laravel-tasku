import { Form, Head, Link } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { login, logout } from '@/routes';
import { accept } from '@/routes/invitation';

export default function AcceptInvitation({
    workspaceName,
    email,
    roleLabel,
    needsAccount,
    needsLogin,
    signedInAs,
    isWrongAccount,
    passwordRules,
    token,
}: {
    workspaceName: string;
    email: string;
    roleLabel: string;
    /** No account uses this address yet, so one is created here. */
    needsAccount: boolean;
    /** The address already has an account and nobody is signed in. */
    needsLogin: boolean;
    signedInAs: { name: string; email: string } | null;
    /** Signed in, but as a different account than the invitation is for. */
    isWrongAccount: boolean;
    passwordRules: string | null;
    token: string;
}) {
    return (
        <>
            <Head title="Terima undangan" />

            <div className="flex min-h-dvh items-center justify-center p-6">
                <div className="w-full max-w-md space-y-6">
                    <div className="space-y-3 text-center">
                        <div className="mx-auto flex size-12 items-center justify-center rounded-xl bg-primary text-primary-foreground">
                            <Building2 className="size-6" aria-hidden="true" />
                        </div>

                        <div className="space-y-1">
                            <h1 className="text-xl font-semibold">
                                Bergabung ke {workspaceName}
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                Anda diundang sebagai {roleLabel} dengan email{' '}
                                <span className="font-medium text-foreground">
                                    {email}
                                </span>
                                .
                            </p>
                        </div>
                    </div>

                    {/*
                     * An address that already has an account has to sign in
                     * first: holding the link proves nothing about who is
                     * opening it.
                     */}
                    {needsLogin && (
                        <div className="space-y-4 rounded-lg border p-4 text-center">
                            <p className="text-sm text-muted-foreground">
                                Akun dengan email ini sudah terdaftar. Masuk
                                terlebih dahulu, lalu undangan ini bisa
                                diterima.
                            </p>

                            <Button className="w-full" asChild>
                                <Link href={login()}>Masuk untuk lanjut</Link>
                            </Button>
                        </div>
                    )}

                    {isWrongAccount && (
                        <div className="space-y-4 rounded-lg border p-4 text-center">
                            <p className="text-sm text-muted-foreground">
                                Anda masuk sebagai{' '}
                                <span className="font-medium text-foreground">
                                    {signedInAs?.email}
                                </span>
                                , sedangkan undangan ini untuk {email}. Keluar
                                dulu, lalu masuk dengan akun yang diundang.
                            </p>

                            <Button
                                variant="outline"
                                className="w-full"
                                asChild
                            >
                                <Link href={logout()} as="button">
                                    Keluar
                                </Link>
                            </Button>
                        </div>
                    )}

                    {!needsLogin && !isWrongAccount && (
                        <Form
                            {...accept.form(token)}
                            resetOnError={['password', 'password_confirmation']}
                            className="space-y-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    {needsAccount && (
                                        <>
                                            <div className="grid gap-2">
                                                <Label htmlFor="name">
                                                    Nama lengkap
                                                </Label>
                                                <Input
                                                    id="name"
                                                    name="name"
                                                    required
                                                    autoFocus
                                                    autoComplete="name"
                                                    placeholder="Nama Anda"
                                                />
                                                <InputError
                                                    message={errors.name}
                                                />
                                            </div>

                                            <div className="grid gap-2">
                                                <Label htmlFor="password">
                                                    Kata sandi
                                                </Label>
                                                <PasswordInput
                                                    id="password"
                                                    name="password"
                                                    required
                                                    autoComplete="new-password"
                                                    placeholder="Kata sandi baru"
                                                    passwordrules={
                                                        passwordRules ??
                                                        undefined
                                                    }
                                                />
                                                <InputError
                                                    message={errors.password}
                                                />
                                            </div>

                                            <div className="grid gap-2">
                                                <Label htmlFor="password_confirmation">
                                                    Konfirmasi kata sandi
                                                </Label>
                                                <PasswordInput
                                                    id="password_confirmation"
                                                    name="password_confirmation"
                                                    required
                                                    autoComplete="new-password"
                                                    placeholder="Ulangi kata sandi"
                                                    passwordrules={
                                                        passwordRules ??
                                                        undefined
                                                    }
                                                />
                                                <InputError
                                                    message={
                                                        errors.password_confirmation
                                                    }
                                                />
                                            </div>
                                        </>
                                    )}

                                    {!needsAccount && (
                                        <p className="text-center text-sm text-muted-foreground">
                                            Anda masuk sebagai{' '}
                                            {signedInAs?.name}. Lanjutkan untuk
                                            bergabung ke workspace ini.
                                        </p>
                                    )}

                                    <Button
                                        className="w-full"
                                        disabled={processing}
                                    >
                                        {processing
                                            ? 'Memproses…'
                                            : 'Terima undangan'}
                                    </Button>
                                </>
                            )}
                        </Form>
                    )}
                </div>
            </div>
        </>
    );
}
