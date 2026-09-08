import {Laptop, Moon, Sun } from 'lucide-react';
import * as React from 'react';
import { useEffect, useRef, useState } from 'react';

interface NavbarProps {
  /**
   * There is deliberately no `isAdmin`. This application has no global administrator role:
   * authority is a workspace membership row, so the chrome has nothing to assert about it.
   */
  authenticated: boolean;
}

type ThemeMode = 'system' | 'dark' | 'light';

function applyTheme(mode: ThemeMode) {
  const root = document.documentElement;
  const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
  const isDark = mode === 'dark' || (mode === 'system' && prefersDark);
  root.classList.toggle('dark', isDark);
}

export default function Navbar({ authenticated }: NavbarProps) {
  const [toolsOpen, setToolsOpen] = useState(false);
  const toolsRef = useRef<HTMLLIElement | null>(null);
  const [theme, setTheme] = useState<ThemeMode>(() => (localStorage.getItem('theme') as ThemeMode) || 'system');

  useEffect(() => {
    applyTheme(theme);
    localStorage.setItem('theme', theme);
  }, [theme]);

  useEffect(() => {
    const handler = (e: MouseEvent) => {
      if (!toolsRef.current) return;
      if (!toolsRef.current.contains(e.target as Node)) setToolsOpen(false);
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, []);

  useEffect(() => {
    const mq = window.matchMedia('(prefers-color-scheme: dark)');
    const onChange = () => {
      const saved = (localStorage.getItem('theme') as ThemeMode) || 'system';
      if (saved === 'system') applyTheme('system');
    };
    mq.addEventListener('change', onChange);
    return () => mq.removeEventListener('change', onChange);
  }, []);

  return (
    <nav className='mx-auto max-w-7xl px-4 py-3 flex items-center justify-between gap-4'>
      {/* Left: Branding + Main nav */}
      <div className='flex items-center gap-6'>
        <a href='/' className='select-none'>
          <h1 className='text-lg font-semibold tracking-tight'>Application</h1>
        </a>
        <ul className='hidden md:flex items-center gap-4 text-sm'>
          <li><a className='hover:underline underline-offset-4' href='/'>Home</a></li>
          {/* The one thing the chrome can honestly say about the visitor. */}
          {authenticated ? (
            <li><a className='hover:underline underline-offset-4' href='/dashboard'>Workspaces</a></li>
          ) : (
            <li><a className='hover:underline underline-offset-4' href='/login'>Sign in</a></li>
          )}
        </ul>
      </div>

      {/* Right: Theme toggle */}
      <div className='flex items-center gap-3'>
        {/* Tri-state theme toggle */}
        <div className='inline-flex items-center overflow-hidden rounded-md border border-gray-200 dark:border-[#3E3E3A]'>
          <button
            type='button'
            onClick={() => setTheme('system')}
            className={`px-2 py-1.5 hover:bg-gray-50 dark:hover:bg-[#1f1f1e] ${
              theme === 'system'
                ? 'bg-gray-900 text-white dark:bg-[#262625] dark:text-gray-50'
                : 'text-gray-500 dark:text-gray-400'
            }`}
            title='System'
            aria-pressed={theme === 'system'}
          >
            <Laptop className='w-4 h-4' />
          </button>
          <button
            type='button'
            onClick={() => setTheme('dark')}
            className={`px-2 py-1.5 hover:bg-gray-50 dark:hover:bg-[#1f1f1e] ${
              theme === 'dark'
                ? 'bg-gray-900 text-white dark:bg-[#262625] dark:text-gray-50'
                : 'text-gray-500 dark:text-gray-400'
            }`}
            title='Dark'
            aria-pressed={theme === 'dark'}
          >
            <Moon className='w-4 h-4' />
          </button>
          <button
            type='button'
            onClick={() => setTheme('light')}
            className={`px-2 py-1.5 hover:bg-gray-50 dark:hover:bg-[#1f1f1e] ${
              theme === 'light'
                ? 'bg-gray-900 text-white dark:bg-[#262625] dark:text-gray-50'
                : 'text-gray-500 dark:text-gray-400'
            }`}
            title='Light'
            aria-pressed={theme === 'light'}
          >
            <Sun className='w-4 h-4' />
          </button>
        </div>
      </div>
    </nav>
  );
}
