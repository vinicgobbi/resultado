import { Form, Head } from '@inertiajs/react';
import Logo from '@/components/logo';
import { store } from '@/routes/login';

export default function Login() {
    return (
        <>
            <Head title="Entrar" />
            <div className="flex min-h-screen items-center justify-center bg-subtle px-4 text-ink">
                <div className="w-full max-w-sm rounded-2xl border border-line bg-surface p-6 shadow-sm">
                    <Logo className="mb-4" />
                    <h1 className="mb-1 text-lg font-bold text-ink">
                        Área administrativa
                    </h1>
                    <p className="mb-6 text-sm text-muted">
                        Entre com seu e-mail e senha.
                    </p>

                    <Form
                        {...store.form()}
                        resetOnError={['password']}
                        className="space-y-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <label className="block text-sm">
                                    <span className="mb-1 block font-medium">
                                        E-mail
                                    </span>
                                    <input
                                        type="email"
                                        name="email"
                                        required
                                        autoFocus
                                        autoComplete="email"
                                        className="w-full rounded-lg border border-line-strong bg-surface px-3 py-2"
                                    />
                                    {errors.email && (
                                        <span className="mt-1 block text-xs text-live">
                                            {errors.email}
                                        </span>
                                    )}
                                </label>
                                <label className="block text-sm">
                                    <span className="mb-1 block font-medium">
                                        Senha
                                    </span>
                                    <input
                                        type="password"
                                        name="password"
                                        required
                                        autoComplete="current-password"
                                        className="w-full rounded-lg border border-line-strong bg-surface px-3 py-2"
                                    />
                                    {errors.password && (
                                        <span className="mt-1 block text-xs text-live">
                                            {errors.password}
                                        </span>
                                    )}
                                </label>
                                <label className="flex items-center gap-2 text-sm text-muted">
                                    <input type="checkbox" name="remember" />
                                    Manter conectado
                                </label>
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="w-full rounded-lg bg-primary px-4 py-2 font-medium text-on-primary hover:bg-primary-hover disabled:opacity-50"
                                >
                                    {processing ? 'Entrando...' : 'Entrar'}
                                </button>
                            </>
                        )}
                    </Form>
                </div>
            </div>
        </>
    );
}
