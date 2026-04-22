

interface ToastOptions {
  message: string;
  type?: 'success' | 'error' | 'info';
  duration?: number;
}

class ToastManager {
  private container: HTMLElement | null = null

  private ensureContainer() {
    if (this.container && document.body.contains(this.container)) {
      return
    }

    if (typeof document !== 'undefined' && document.body) {
      this.container = document.createElement('div')
      this.container.id = 'anibas-toast-container'
      this.container.style.cssText = 'position: fixed !important; top: 20px !important; right: 20px !important; z-index: 100001 !important; pointer-events: none !important;'
      document.body.appendChild(this.container)
    }
  }

  show(options: ToastOptions) {
    try {
      this.ensureContainer()
      if (!this.container) {
        return
      }

      const type = options.type ?? 'success'
      const toast = document.createElement('div')
      toast.className = `anibas-toast anibas-toast-${type}`
      toast.setAttribute('role', type === 'error' ? 'alert' : 'status')
      toast.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite')

      const content = document.createElement('div')
      content.className = 'anibas-toast-content'

      const icon = document.createElement('span')
      icon.className = 'anibas-toast-icon'
      icon.setAttribute('aria-hidden', 'true')
      icon.textContent = this.getIcon(type)

      const message = document.createElement('span')
      message.className = 'anibas-toast-message'
      message.textContent = options.message

      const closeBtn = document.createElement('button')
      closeBtn.className = 'anibas-toast-close'
      closeBtn.type = 'button'
      closeBtn.setAttribute('aria-label', 'Close notification')
      closeBtn.textContent = '✕'
      closeBtn.addEventListener('click', () => this.removeToast(toast))

      content.append(icon, message, closeBtn)
      toast.appendChild(content)

      this.applyStyles(toast)
      this.container.appendChild(toast)

      setTimeout(() => toast.classList.add('anibas-toast-visible'), 10)

      const duration = options.duration ?? 3000
      if (duration > 0) {
        setTimeout(() => this.removeToast(toast), duration)
      }
    } catch (e) {
      console.error('Error showing toast:', e)
    }
  }

  private removeToast(toast: HTMLElement) {
    toast.classList.remove('anibas-toast-visible')
    setTimeout(() => toast.remove(), 300)
  }

  private getIcon(type: string): string {
    switch (type) {
      case 'success': return '✓'
      case 'error': return '✕'
      case 'info': return 'ℹ'
      default: return '✓'
    }
  }

  private escapeHtml(text: string): string {
    const div = document.createElement('div')
    div.textContent = text
    return div.innerHTML
  }

  private applyStyles(toast: HTMLElement) {
    // Set initial styles without opacity and transform
    toast.style.display = 'block'
    toast.style.minWidth = '300px'
    toast.style.maxWidth = '500px'
    toast.style.padding = '16px 20px'
    toast.style.borderRadius = '4px'
    toast.style.boxShadow = '0 4px 12px rgba(0, 0, 0, 0.15)'
    toast.style.marginBottom = '10px'
    toast.style.position = 'relative'
    toast.style.zIndex = '100002'
    toast.style.pointerEvents = 'auto'
    toast.style.transition = 'all 0.3s ease'
    
    // Set initial animation state
    toast.style.opacity = '0'
    toast.style.transform = 'translateX(400px)'

    if (toast.classList.contains('anibas-toast-success')) {
      toast.style.background = '#4caf50'
      toast.style.color = 'white'
    } else if (toast.classList.contains('anibas-toast-error')) {
      toast.style.background = '#f44336'
      toast.style.color = 'white'
    } else if (toast.classList.contains('anibas-toast-info')) {
      toast.style.background = '#2196f3'
      toast.style.color = 'white'
    }

    const content = toast.querySelector('.anibas-toast-content') as HTMLElement
    if (content) {
      content.style.display = 'flex'
      content.style.alignItems = 'center'
      content.style.gap = '12px'
    }

    const icon = toast.querySelector('.anibas-toast-icon') as HTMLElement
    if (icon) {
      icon.style.fontSize = '20px'
      icon.style.fontWeight = 'bold'
    }

    const message = toast.querySelector('.anibas-toast-message') as HTMLElement
    if (message) {
      message.style.flex = '1'
      message.style.fontSize = '14px'
    }

    const closeBtn = toast.querySelector('.anibas-toast-close') as HTMLElement
    if (closeBtn) {
      closeBtn.style.background = 'transparent'
      closeBtn.style.border = 'none'
      closeBtn.style.color = 'white'
      closeBtn.style.fontSize = '18px'
      closeBtn.style.cursor = 'pointer'
      closeBtn.style.padding = '0'
      closeBtn.style.width = '20px'
      closeBtn.style.height = '20px'
      closeBtn.style.display = 'flex'
      closeBtn.style.alignItems = 'center'
      closeBtn.style.justifyContent = 'center'
      closeBtn.style.opacity = '0.7'
      closeBtn.onmouseover = () => closeBtn.style.opacity = '1'
      closeBtn.onmouseout = () => closeBtn.style.opacity = '0.7'
    }

    requestAnimationFrame(() => {
      toast.style.opacity = '1'
      toast.style.transform = 'translateX(0)'
    })
  }

  success(message: string) {
    this.show({ message, type: 'success' })
  }

  error(message: string) {
    this.show({ message, type: 'error' })
  }

  info(message: string) {
    this.show({ message, type: 'info' })
  }
}

export const toast = new ToastManager()
