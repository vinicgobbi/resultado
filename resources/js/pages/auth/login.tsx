import { Form, Head } from '@inertiajs/react';
import { store } from '@/routes/login';

export default function Login() {
    return (
        <>
            <Head title="Entrar" />
            <div className="flex min-h-screen items-center justify-center bg-zinc-50 px-4 dark:bg-zinc-950">
                <div className="w-full max-w-sm rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                    <img
                        src="/app-icons/icon-192.png"
                        alt=""
                        className="mb-4 size-10 rounded-lg"
                    />
                    <h1 className="mb-1 text-lg font-bold text-zinc-900 dark:text-zinc-50">
                        Área administrativa
                    </h1>
                    <p className="mb-6 text-sm text-zinc-500">
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
                                        className="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950"
                                    />
                                    {errors.email && (
                                        <span className="mt-1 block text-xs text-red-600">
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
                                        className="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950"
                                    />
                                    {errors.password && (
                                        <span className="mt-1 block text-xs text-red-600">
                                            {errors.password}
                                        </span>
                                    )}
                                </label>
                                <label className="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-400">
                                    <input type="checkbox" name="remember" />
                                    Manter conectado
                                </label>
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="w-full rounded-lg bg-emerald-600 px-4 py-2 font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
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
