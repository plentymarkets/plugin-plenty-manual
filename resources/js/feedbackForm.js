const FEEDBACK_TTL = 12 * 60 * 60 * 1000

class FeedbackHandler {
    constructor ({ space, key, token, page, selector }) {
        this.page = page
        this.pageHash = this.createPageHash(window.location.href)
        this.selector = selector
        this.endpoint = `https://chat.googleapis.com/v1/spaces/${space}/messages?key=${key}&token=${token}`
        this.isSubmitting = false
        this.initializeForm()
        this.registerListener()
    }

    registerListener () {
        $(this.selector + ' button[type=submit]').on('click', (event) => this.submitHandler(event))
    }

    initializeForm () {
        if (this.feedbackWasGivenBefore()) {
            this.hideForm()
        }
    }

    hideForm () {
        $(this.selector).hide()
        $(this.selector + '+ .feedback-submitted').removeClass('d-none')
    }

    submitHandler (event) {
        event.preventDefault()

        const target = event.currentTarget

        const opinion = target.getAttribute('data-opinion')
        const location = target.getAttribute('data-location')
        const textareaSelector = this.selector + '[data-location=' + location + '] textarea[name=feedback]'
        const message = $(textareaSelector).val() || 'No message was specified'

        const requestData = {
            text: `New ${opinion} feedback on page: <${window.location.href}|${this.page}>\n\n` +
                `Message below\n${message}`,
        }

        this.sendFeedback(requestData)
    }

    feedbackWasGivenBefore () {
        const feedbackTime = window.localStorage.getItem('feedback_' + this.pageHash)
        if (!feedbackTime) {
            return false
        }

        const now = new Date()

        if (now > JSON.parse(feedbackTime)) {
            window.localStorage.removeItem('feedback_' + this.pageHash)
            return false
        }

        return true
    }

    sendFeedback (data) {
        if (this.isSubmitting) {
            return
        }

        $(this.selector).addClass('disabled')
        this.isSubmitting = true

        $.ajax(
            {
                type: 'POST',
                url: this.endpoint,
                dataType: 'json',
                contentType: 'application/json',
                data: JSON.stringify(data),
                success: () => {
                    this.onSuccess()
                },
                complete: () => {
                    $(this.selector).removeClass('disabled')
                    this.isSubmitting = false
                },
            }
        )
    }

    onSuccess () {
        const now = new Date()
        window.localStorage.setItem('feedback_' + this.pageHash, JSON.stringify(now.getTime() + FEEDBACK_TTL))
        this.hideForm()
    }

    createPageHash (string) {
        let hash = 0

        if (string.length === 0) {
            return hash
        }

        for (let i = 0; i < string.length; i++) {
            const char = string.charCodeAt(i)
            hash = ((hash << 5) - hash) + char
            hash = hash & hash
        }

        return hash
    }
}

(function () {
    $(document).ready(function () {
        // eslint-disable-next-line
        new FeedbackHandler({
            space: window.Feedback.space,
            key: window.Feedback.key,
            token: window.Feedback.token,
            page: window.Feedback.page,
            selector: 'form.feedback-form',
        })

        console.log(window)

        $('.feedback-popup, .fa-times').on('click touch', function () {
            $('.feedback-form-popup-wrapper').toggleClass('d-flex')
        })
    })
})()